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

    public function testNoteKeyMatchesExactlyEvenWithSameAmountSameDay(): void
    {
        // Two transfers of the same amount on the same day, each with its own
        // keyed payment (provider fields null, as UISP returns them). Each must be
        // linked to ITS payment — the heuristic alone could not tell them apart.
        $txA = '055d7bd0-0015-e343-0b40-032d2bd81330';
        $txB = '0561ff1b-f5e5-e376-0b40-0352c56d7548';
        $transactions = [$this->tx($txA, 12.44), $this->tx($txB, 12.44)];
        $payments = [
            ['id' => 2, 'clientId' => 20, 'providerName' => null, 'providerPaymentId' => null, 'note' => 'Revolut: B | tx:' . $txB, 'amount' => 12.44, 'createdDate' => '2026-06-15T10:00:00+0300'],
            ['id' => 1, 'clientId' => null, 'providerName' => null, 'providerPaymentId' => null, 'note' => 'Revolut: A | tx:' . $txA, 'amount' => 12.44, 'createdDate' => '2026-06-15T10:00:00+0300'],
        ];

        $rows = (new MonthlyStatusReport())->build($transactions, $payments, self::notProcessed());

        $byTx = [];
        foreach ($rows as $row) {
            $byTx[$row->transactionId] = [$row->status, $row->clientId];
        }
        self::assertSame([StatusRow::STATUS_UNASSIGNED, null], $byTx[$txA]);
        self::assertSame([StatusRow::STATUS_ASSIGNED, 20], $byTx[$txB]);
    }

    public function testKeyedPaymentOfAnotherTransactionIsNotUsedByTheHeuristic(): void
    {
        // tx-c has no payment; the keyed payment belongs to another transaction and
        // must not be borrowed by amount + date.
        $payments = [
            ['id' => 3, 'clientId' => 30, 'providerName' => null, 'note' => 'Revolut: X | tx:0561ff1b-f5e5-e376-0b40-0352c56d7548', 'amount' => 50.0, 'createdDate' => '2026-06-15T10:00:00+0300'],
        ];

        $rows = (new MonthlyStatusReport())->build([$this->tx('tx-c', 50.0)], $payments, self::notProcessed());

        self::assertSame(StatusRow::STATUS_MISSING, $rows[0]->status);
    }

    public function testLegacyHeuristicComparesTheUtcDate(): void
    {
        // A transfer completed at 22:30Z on the 14th; its legacy (pre-key) payment
        // was written as that instant and UISP returns it as 01:30+03:00 on the
        // 15th. Comparing local dates would call it missing — and the re-import
        // button on such a GONE row would duplicate the payment.
        $transfer = $this->tx('tx-late', 8.86, ['completed_at' => '2026-06-14T22:30:00Z']);
        $payments = [
            ['id' => 4, 'clientId' => 40, 'providerName' => null, 'providerPaymentId' => null, 'note' => 'Revolut: ACME', 'amount' => 8.86, 'createdDate' => '2026-06-15T01:30:00+0300'],
        ];

        $rows = (new MonthlyStatusReport())->build([$transfer], $payments, static fn (string $id): bool => true);

        self::assertSame(StatusRow::STATUS_ASSIGNED, $rows[0]->status);
        self::assertSame(40, $rows[0]->clientId);
    }

    public function testInternalReleaseBetweenOwnAccountsIsExcluded(): void
    {
        // A hold/release move (a negative leg on another own account) is not an
        // incoming transfer — it must not appear, and must never offer re-import.
        $release = $this->tx('tx-rel', 288.87, ['legs' => [
            ['account_id' => 'acc-hold', 'amount' => -288.87, 'currency' => 'EUR', 'description' => 'Release'],
            ['account_id' => 'acc-1', 'amount' => 288.87, 'currency' => 'EUR'],
        ]]);

        $rows = (new MonthlyStatusReport())->build([$release, $this->tx('tx-real', 12.44)], [], static fn (string $id): bool => true);

        self::assertSame(['tx-real'], array_map(static fn (StatusRow $r): string => $r->transactionId, $rows));
    }

    public function testRevertedTransactionWithRecordedPaymentYieldsRevertedRow(): void
    {
        $tx = $this->tx('tx-rev', 100.0, ['state' => 'reverted']);
        $payments = [[
            'providerName' => 'Revolut',
            'providerPaymentId' => 'tx-rev',
            'clientId' => 7,
            'amount' => 100.0,
            'createdDate' => '2026-06-15T10:00:00Z',
        ]];

        $report = new MonthlyStatusReport();
        $rows = $report->build([$tx], $payments, static fn (string $id): bool => true);

        self::assertCount(1, $rows);
        self::assertSame(StatusRow::STATUS_REVERTED, $rows[0]->status);
        self::assertSame(7, $rows[0]->clientId);
        self::assertSame(1, $report->summarize($rows)[StatusRow::STATUS_REVERTED]['count']);
    }

    public function testRevertedTransactionWithoutPaymentIsSkipped(): void
    {
        $tx = $this->tx('tx-rev', 100.0, ['state' => 'reverted']);

        self::assertSame([], (new MonthlyStatusReport())->build([$tx], [], self::notProcessed()));
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

    public function testProcessedTransferWithoutAnyPaymentIsGone(): void
    {
        $isProcessed = static fn (string $id): bool => $id === 'tx-manual';

        $rows = (new MonthlyStatusReport())->build([$this->tx('tx-manual')], [], $isProcessed);

        self::assertSame(StatusRow::STATUS_GONE, $rows[0]->status);
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
        self::assertSame(0, $summary[StatusRow::STATUS_GONE]['count']);
    }

    public function testProcessedRowWithManualPaymentForResolvedClientIsSkipped(): void
    {
        $manual = $this->payment(['clientId' => 42, 'note' => 'платено на каса', 'providerName' => null, 'providerPaymentId' => null]);
        $resolver = static fn (string $sender): ?int => 42;

        $row = (new MonthlyStatusReport())->build(
            [$this->tx('tx-m')],
            [$manual],
            static fn (string $id): bool => true,
            $resolver,
        )[0];

        self::assertSame(StatusRow::STATUS_SKIPPED, $row->status);
        self::assertSame(42, $row->clientId);
    }

    public function testProcessedRowWithoutPaymentIsGoneWithExpectedClient(): void
    {
        $resolver = static fn (string $sender): ?int => 42;

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-g')], [], static fn (string $id): bool => true, $resolver)[0];

        self::assertSame(StatusRow::STATUS_GONE, $row->status);
        self::assertSame(42, $row->clientId);
    }

    public function testManualPaymentOfAnotherClientDoesNotVerifyTheSkip(): void
    {
        $foreign = $this->payment(['clientId' => 7, 'note' => 'каса', 'providerName' => null, 'providerPaymentId' => null]);
        $resolver = static fn (string $sender): ?int => 42;

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-g2')], [$foreign], static fn (string $id): bool => true, $resolver)[0];

        self::assertSame(StatusRow::STATUS_GONE, $row->status);
        self::assertSame(42, $row->clientId);
    }

    public function testPluginCreatedPaymentDoesNotCountAsManual(): void
    {
        // Same amount/date but created by the plugin for ANOTHER transfer.
        $pluginPayment = $this->payment(['clientId' => 42, 'providerName' => 'Revolut', 'providerPaymentId' => 'tx-OTHER']);

        $row = (new MonthlyStatusReport())->build(
            [$this->tx('tx-g3')],
            [$pluginPayment],
            static fn (string $id): bool => true,
            static fn (string $sender): ?int => 42,
        )[0];

        self::assertSame(StatusRow::STATUS_GONE, $row->status);
    }

    public function testUnresolvedSenderAcceptsAnyManualPaymentAsSkipReason(): void
    {
        $manual = $this->payment(['clientId' => 7, 'note' => 'каса', 'providerName' => null, 'providerPaymentId' => null]);

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-u')], [$manual], static fn (string $id): bool => true)[0];

        self::assertSame(StatusRow::STATUS_SKIPPED, $row->status);
        self::assertSame(7, $row->clientId);
    }
}
