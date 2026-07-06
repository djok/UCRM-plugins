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

    public function testFindsSameAmountPaymentOnDay(): void
    {
        $ucrm = $this->ucrm([
            ['id' => 1, 'amount' => 8.86],
            ['id' => 2, 'amount' => 100.0],
        ]);
        $lookup = new UcrmPaymentLookup($ucrm);

        self::assertTrue($lookup->clientHasPaymentOn(42, '2026-05-29', 8.86));
        self::assertSame('payments', $ucrm->queries[0]['endpoint']);
        self::assertSame(42, $ucrm->queries[0]['params']['clientId']);
        self::assertSame('2026-05-29', $ucrm->queries[0]['params']['createdDateFrom']);
        self::assertSame('2026-05-29', $ucrm->queries[0]['params']['createdDateTo']);
    }

    public function testReturnsFalseWhenNoAmountMatches(): void
    {
        $lookup = new UcrmPaymentLookup($this->ucrm([['id' => 1, 'amount' => 9.99]]));

        self::assertFalse($lookup->clientHasPaymentOn(42, '2026-05-29', 8.86));
    }

    public function testToleratesFloatRepresentation(): void
    {
        $lookup = new UcrmPaymentLookup($this->ucrm([['id' => 1, 'amount' => '8.8600']]));

        self::assertTrue($lookup->clientHasPaymentOn(42, '2026-05-29', 8.86));
    }
}
