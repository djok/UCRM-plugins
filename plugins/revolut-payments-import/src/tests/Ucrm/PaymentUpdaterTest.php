<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Ucrm;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Ucrm\PaymentUpdater;
use RevolutPaymentsImport\Ucrm\UcrmClient;

final class PaymentUpdaterTest extends TestCase
{
    public function testAttachClientPatchesPayment(): void
    {
        $ucrm = new class implements UcrmClient {
            /** @var array<int,array{endpoint:string,data:array<string,mixed>}> */
            public array $patched = [];

            public function get(string $endpoint, array $params = []): array
            {
                return [];
            }

            public function post(string $endpoint, array $data): array
            {
                return [];
            }

            public function patch(string $endpoint, array $data): array
            {
                $this->patched[] = ['endpoint' => $endpoint, 'data' => $data];

                return ['id' => 7];
            }
        };

        $ok = (new PaymentUpdater($ucrm))->attachClient(7, 42);

        self::assertTrue($ok);
        self::assertSame('payments/7', $ucrm->patched[0]['endpoint']);
        self::assertSame(['clientId' => 42], $ucrm->patched[0]['data']);
    }

    public function testAttachClientReturnsFalseWhenApiRejects(): void
    {
        $ucrm = new class implements UcrmClient {
            public function get(string $endpoint, array $params = []): array
            {
                return [];
            }

            public function post(string $endpoint, array $data): array
            {
                return [];
            }

            public function patch(string $endpoint, array $data): array
            {
                throw new \RuntimeException('405 Method Not Allowed');
            }
        };

        self::assertFalse((new PaymentUpdater($ucrm))->attachClient(7, 42));
    }
}
