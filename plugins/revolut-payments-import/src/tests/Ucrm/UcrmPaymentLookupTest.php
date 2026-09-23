<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Ucrm;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Ucrm\UcrmClient;
use RevolutPaymentsImport\Ucrm\UcrmPaymentLookup;

final class UcrmPaymentLookupTest extends TestCase
{
    /** @param array<int,array<string,mixed>> $payments */
    private function ucrm(array $payments): object
    {
        return new class($payments) implements UcrmClient {
            /** @var array<int,array<string,mixed>> */
            public array $queries = [];

            public function __construct(private array $payments)
            {
            }

            public function get(string $endpoint, array $params = []): array
            {
                $this->queries[] = ['endpoint' => $endpoint, 'params' => $params];

                return $endpoint === 'payments' ? $this->payments : [];
            }

            public function post(string $endpoint, array $data): array
            {
                return [];
            }

            public function patch(string $endpoint, array $data): array
            {
                return [];
            }
        };
    }

    private const TX = '055d7bd0-0015-e343-0b40-032d2bd81330';
    private const OTHER_TX = '0561ff1b-f5e5-e376-0b40-0352c56d7548';

    public function testFindsSameAmountPaymentOnDay(): void
    {
        $ucrm = $this->ucrm([
            ['id' => 1, 'amount' => 8.86, 'note' => 'платено на каса'],
            ['id' => 2, 'amount' => 100.0],
        ]);
        $lookup = new UcrmPaymentLookup($ucrm);

        self::assertTrue($lookup->clientHasPaymentOn(42, '2026-05-29', 8.86, self::TX));
        self::assertSame('payments', $ucrm->queries[0]['endpoint']);
        self::assertSame(42, $ucrm->queries[0]['params']['clientId']);
        self::assertSame('2026-05-29', $ucrm->queries[0]['params']['createdDateFrom']);
        self::assertSame('2026-05-29', $ucrm->queries[0]['params']['createdDateTo']);
    }

    public function testReturnsFalseWhenNoAmountMatches(): void
    {
        $lookup = new UcrmPaymentLookup($this->ucrm([['id' => 1, 'amount' => 9.99]]));

        self::assertFalse($lookup->clientHasPaymentOn(42, '2026-05-29', 8.86, self::TX));
    }

    public function testToleratesFloatRepresentation(): void
    {
        $lookup = new UcrmPaymentLookup($this->ucrm([['id' => 1, 'amount' => '8.8600']]));

        self::assertTrue($lookup->clientHasPaymentOn(42, '2026-05-29', 8.86, self::TX));
    }

    public function testPluginPaymentForAnotherTransferDoesNotCount(): void
    {
        // The client paid the same amount twice that day; the first transfer was
        // already imported by the plugin. The second one must NOT be dropped as a
        // "manual duplicate".
        $lookup = new UcrmPaymentLookup($this->ucrm([
            ['id' => 1, 'amount' => 12.44, 'note' => 'Revolut: ACME | tx:' . self::OTHER_TX],
        ]));

        self::assertFalse($lookup->clientHasPaymentOn(42, '2026-05-29', 12.44, self::TX));
    }

    public function testPaymentForThisVeryTransferCounts(): void
    {
        // If the history of processed ids were lost, this stops a second import.
        $lookup = new UcrmPaymentLookup($this->ucrm([
            ['id' => 1, 'amount' => 12.44, 'note' => 'Revolut: ACME | tx:' . self::TX],
        ]));

        self::assertTrue($lookup->clientHasPaymentOn(42, '2026-05-29', 12.44, self::TX));
    }

    public function testManualEntryStillCountsNextToAnotherTransfersPayment(): void
    {
        $lookup = new UcrmPaymentLookup($this->ucrm([
            ['id' => 1, 'amount' => 12.44, 'note' => 'Revolut: ACME | tx:' . self::OTHER_TX],
            ['id' => 2, 'amount' => 12.44, 'note' => 'платено по банка'],
        ]));

        self::assertTrue($lookup->clientHasPaymentOn(42, '2026-05-29', 12.44, self::TX));
    }
}
