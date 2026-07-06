<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Status;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Status\MonthlyStatusReport;
use RevolutPaymentsImport\Status\StatusRow;

final class MonthlyStatusReportTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function tx(string $id, float $amount = 50.0, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'state' => 'completed',
            'type' => 'transfer',
            'completed_at' => '2026-06-15T10:00:00Z',
            'reference' => 'Invoice 1',
            'legs' => [[
                'account_id' => 'acc-1',
                'amount' => $amount,
                'currency' => 'EUR',
                'description' => 'Payment from ACME LTD',
            ]],
        ], $overrides);
    }

    private static function notProcessed(): callable
    {
        return static fn (string $id): bool => false;
    }

    public function testCompletedIncomingTransferBecomesRow(): void
    {
        $rows = (new MonthlyStatusReport())->build([$this->tx('tx-1', 42.5)], [], self::notProcessed());

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('tx-1', $row->transactionId);
        self::assertSame('2026-06-15', $row->date);
        self::assertSame(42.5, $row->amount);
        self::assertSame('EUR', $row->currency);
        self::assertSame('ACME LTD', $row->sender);
        self::assertSame('Invoice 1', $row->reference);
        self::assertSame(StatusRow::STATUS_MISSING, $row->status);
        self::assertNull($row->clientId);
    }

    public function testNonCompletedAndNonIncomingTypesAreExcluded(): void
    {
        $transactions = [
            $this->tx('pending', 10.0, ['state' => 'pending']),
            $this->tx('card', 10.0, ['type' => 'card_payment']),
            $this->tx('exchange', 10.0, ['type' => 'exchange']),
            $this->tx('topup', 10.0, ['type' => 'topup']),
        ];

        $rows = (new MonthlyStatusReport())->build($transactions, [], self::notProcessed());

        self::assertSame(['topup'], array_map(static fn (StatusRow $r): string => $r->transactionId, $rows));
    }

    public function testOutgoingTransfersAreExcluded(): void
    {
        $out = $this->tx('out', -20.0, ['legs' => [['account_id' => 'acc-1', 'amount' => -20.0, 'currency' => 'EUR']]]);

        self::assertSame([], (new MonthlyStatusReport())->build([$out], [], self::notProcessed()));
    }

    public function testAccountFilterIsCaseInsensitive(): void
    {
        $report = new MonthlyStatusReport(['ACC-1']);
        $transactions = [
            $this->tx('kept'),
            $this->tx('dropped', 50.0, ['legs' => [['account_id' => 'acc-2', 'amount' => 50.0, 'currency' => 'EUR']]]),
        ];

        $rows = $report->build($transactions, [], self::notProcessed());

        self::assertSame(['kept'], array_map(static fn (StatusRow $r): string => $r->transactionId, $rows));
    }

    public function testProcessedTransferWithoutPaymentIsSkippedStatus(): void
    {
        $isProcessed = static fn (string $id): bool => $id === 'tx-manual';

        $rows = (new MonthlyStatusReport())->build([$this->tx('tx-manual')], [], $isProcessed);

        self::assertSame(StatusRow::STATUS_SKIPPED, $rows[0]->status);
    }

    public function testDateFallsBackToCreatedAtAndBulgarianDescriptionPrefixIsStripped(): void
    {
        $tx = $this->tx('tx-bg', 5.0, [
            'completed_at' => null,
            'created_at' => '2026-06-02T08:00:00Z',
            'reference' => '',
            'legs' => [[
                'account_id' => 'acc-1',
                'amount' => 5.0,
                'currency' => 'EUR',
                'description' => 'Добавени пари от ИВАН ИВАНОВ',
            ]],
        ]);

        $row = (new MonthlyStatusReport())->build([$tx], [], self::notProcessed())[0];

        self::assertSame('2026-06-02', $row->date);
        self::assertSame('ИВАН ИВАНОВ', $row->sender);
        self::assertSame('', $row->reference);
    }

    /** @param array<string,mixed> $overrides */
    private function payment(array $overrides = []): array
    {
        return array_replace([
            'id' => 1,
            'clientId' => 42,
            'amount' => 50.0,
            'currencyCode' => 'EUR',
            'note' => 'Revolut: ACME LTD | Invoice 1',
            'createdDate' => '2026-06-15T10:00:00+00:00',
            'providerName' => null,
            'providerPaymentId' => null,
        ], $overrides);
    }

    public function testExactProviderIdMatchIsAssigned(): void
    {
        $payment = $this->payment(['providerName' => 'Revolut', 'providerPaymentId' => 'tx-1', 'amount' => 999.0]);

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-1')], [$payment], self::notProcessed())[0];

        self::assertSame(StatusRow::STATUS_ASSIGNED, $row->status);
        self::assertSame(42, $row->clientId);
    }

    public function testExactMatchWithoutClientIsUnassigned(): void
    {
        $payment = $this->payment(['providerName' => 'Revolut', 'providerPaymentId' => 'tx-1', 'clientId' => null]);

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-1')], [$payment], self::notProcessed())[0];

        self::assertSame(StatusRow::STATUS_UNASSIGNED, $row->status);
        self::assertNull($row->clientId);
    }

    public function testForeignProviderIdIsNotExactMatched(): void
    {
        $payment = $this->payment(['providerName' => 'Fio CZ', 'providerPaymentId' => 'tx-1', 'note' => 'other', 'amount' => 999.0]);

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-1')], [$payment], self::notProcessed())[0];

        self::assertSame(StatusRow::STATUS_MISSING, $row->status);
    }

    public function testLegacyPaymentMatchesByNoteAmountAndDate(): void
    {
        $row = (new MonthlyStatusReport())->build([$this->tx('tx-1')], [$this->payment()], self::notProcessed())[0];

        self::assertSame(StatusRow::STATUS_ASSIGNED, $row->status);
        self::assertSame(42, $row->clientId);
    }

    public function testLegacyMatchRejectsDifferentAmountOrDate(): void
    {
        $report = new MonthlyStatusReport();
        $wrongAmount = $this->payment(['amount' => 50.01]);
        $wrongDate = $this->payment(['createdDate' => '2026-06-16T10:00:00+00:00']);

        self::assertSame(StatusRow::STATUS_MISSING, $report->build([$this->tx('tx-1')], [$wrongAmount], self::notProcessed())[0]->status);
        self::assertSame(StatusRow::STATUS_MISSING, $report->build([$this->tx('tx-1')], [$wrongDate], self::notProcessed())[0]->status);
    }

    public function testManualPaymentWithoutRevolutNoteIsNeverHeuristicallyMatched(): void
    {
        $manual = $this->payment(['note' => 'платено на каса']);

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-1')], [$manual], self::notProcessed())[0];

        self::assertSame(StatusRow::STATUS_MISSING, $row->status);
    }

    public function testLegacyPaymentIsConsumedByFirstTransferOnly(): void
    {
        $transfers = [$this->tx('tx-1'), $this->tx('tx-2')];

        $rows = (new MonthlyStatusReport())->build($transfers, [$this->payment()], self::notProcessed());

        self::assertSame(
            [StatusRow::STATUS_ASSIGNED, StatusRow::STATUS_MISSING],
            array_map(static fn (StatusRow $r): string => $r->status, $rows),
        );
    }

    public function testDuplicatedBoundaryTransactionYieldsOneRow(): void
    {
        $tx = $this->tx('tx-dup');

        $rows = (new MonthlyStatusReport())->build([$tx, $tx], [$this->payment()], self::notProcessed());

        self::assertCount(1, $rows);
        self::assertSame(StatusRow::STATUS_ASSIGNED, $rows[0]->status);
    }

    public function testSummarizeCountsAndSumsPerCurrency(): void
    {
        $rows = [
            new StatusRow('a', '2026-06-01', 10.0, 'EUR', '', '', StatusRow::STATUS_ASSIGNED, 1),
            new StatusRow('b', '2026-06-02', 5.5, 'EUR', '', '', StatusRow::STATUS_ASSIGNED, 2),
            new StatusRow('c', '2026-06-03', 7.0, 'USD', '', '', StatusRow::STATUS_ASSIGNED, 3),
            new StatusRow('d', '2026-06-04', 99.0, 'EUR', '', '', StatusRow::STATUS_MISSING),
        ];

        $summary = (new MonthlyStatusReport())->summarize($rows);

        self::assertSame(3, $summary[StatusRow::STATUS_ASSIGNED]['count']);
        self::assertSame(15.5, $summary[StatusRow::STATUS_ASSIGNED]['amounts']['EUR']);
        self::assertSame(7.0, $summary[StatusRow::STATUS_ASSIGNED]['amounts']['USD']);
        self::assertSame(1, $summary[StatusRow::STATUS_MISSING]['count']);
        self::assertSame(0, $summary[StatusRow::STATUS_UNASSIGNED]['count']);
        self::assertSame([], $summary[StatusRow::STATUS_SKIPPED]['amounts']);
    }
}
