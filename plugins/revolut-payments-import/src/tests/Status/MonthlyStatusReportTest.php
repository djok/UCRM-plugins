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
}
