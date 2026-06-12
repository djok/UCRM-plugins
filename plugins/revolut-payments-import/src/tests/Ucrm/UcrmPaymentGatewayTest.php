<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Ucrm;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Ucrm\IncomingPayment;
use RevolutPaymentsImport\Ucrm\UcrmClient;
use RevolutPaymentsImport\Ucrm\UcrmPaymentGateway;

final class UcrmPaymentGatewayTest extends TestCase
{
    private function ucrm(): object
    {
        return new class implements UcrmClient {
            /** @var array<int,array<string,mixed>> */
            public array $posted = [];

            public function get(string $endpoint, array $params = []): array
            {
                if ($endpoint === 'payment-methods') {
                    return [
                        ['id' => 'uuid-cash', 'name' => 'Cash'],
                        ['id' => 'uuid-bank', 'name' => 'Bank transfer'],
                    ];
                }

                return [];
            }

            public function post(string $endpoint, array $data): array
            {
                $this->posted[] = ['endpoint' => $endpoint, 'data' => $data];

                return ['id' => 999];
            }
        };
    }

    public function testRecordsAssignedPaymentWithResolvedMethodId(): void
    {
        $ucrm = $this->ucrm();
        $gateway = new UcrmPaymentGateway($ucrm, 'Bank transfer');

        $gateway->record(new IncomingPayment(12.44, 'EUR', 42, 'Revolut: John', 'tx-1'));

        self::assertCount(1, $ucrm->posted);
        self::assertSame('payments', $ucrm->posted[0]['endpoint']);
        $data = $ucrm->posted[0]['data'];
        self::assertSame(12.44, $data['amount']);
        self::assertSame('uuid-bank', $data['methodId']);
        self::assertSame(42, $data['clientId']);
        self::assertSame('EUR', $data['currencyCode']);
        self::assertStringContainsString('John', $data['note']);
    }

    public function testUnassignedPaymentOmitsClientId(): void
    {
        $ucrm = $this->ucrm();
        $gateway = new UcrmPaymentGateway($ucrm, 'Bank transfer');

        $gateway->record(new IncomingPayment(5.0, 'BGN', null, 'Revolut: unknown', 'tx-2'));

        $data = $ucrm->posted[0]['data'];
        self::assertArrayNotHasKey('clientId', $data);
    }

    public function testResolvesMethodByIdToo(): void
    {
        $ucrm = $this->ucrm();
        // The admin pasted the method UUID instead of its name — must still resolve.
        $gateway = new UcrmPaymentGateway($ucrm, 'uuid-bank');

        $gateway->record(new IncomingPayment(3.5, 'EUR', 7, 'Revolut: note', 'tx-9'));

        self::assertSame('uuid-bank', $ucrm->posted[0]['data']['methodId']);
    }

    public function testResolvesMethodNameWithSurroundingWhitespace(): void
    {
        $ucrm = $this->ucrm();
        $gateway = new UcrmPaymentGateway($ucrm, '  Bank transfer ');

        $gateway->record(new IncomingPayment(1.0, 'EUR', 7, 'n', 'tx-10'));

        self::assertSame('uuid-bank', $ucrm->posted[0]['data']['methodId']);
    }

    public function testThrowsWhenMethodNameNotFound(): void
    {
        $ucrm = $this->ucrm();
        $gateway = new UcrmPaymentGateway($ucrm, 'Nonexistent');

        $this->expectException(\RuntimeException::class);
        $gateway->record(new IncomingPayment(1.0, 'EUR', 1, 'x', 'tx-3'));
    }

    public function testMethodIdResolvedOnlyOnce(): void
    {
        $ucrm = new class extends \stdClass implements UcrmClient {
            public int $methodCalls = 0;
            /** @var array<int,array<string,mixed>> */
            public array $posted = [];

            public function get(string $endpoint, array $params = []): array
            {
                if ($endpoint === 'payment-methods') {
                    $this->methodCalls++;

                    return [['id' => 'uuid-bank', 'name' => 'Bank transfer']];
                }

                return [];
            }

            public function post(string $endpoint, array $data): array
            {
                $this->posted[] = ['endpoint' => $endpoint, 'data' => $data];

                return [];
            }
        };
        $gateway = new UcrmPaymentGateway($ucrm, 'Bank transfer');

        $gateway->record(new IncomingPayment(1.0, 'EUR', 1, 'a', 'tx-4'));
        $gateway->record(new IncomingPayment(2.0, 'EUR', 1, 'b', 'tx-5'));

        self::assertSame(1, $ucrm->methodCalls);
        self::assertCount(2, $ucrm->posted);
    }
}
