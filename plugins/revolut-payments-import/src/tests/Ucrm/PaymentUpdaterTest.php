<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Ucrm;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Support\Logger;
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
        // UISP rejects clientId on PATCH payments/{id} (422 "This field is not
        // allowed") — an unmatched payment is attached via the dedicated endpoint.
        // No receipt e-mail is sent to the customer on a background re-match.
        self::assertSame('payments/7/attach', $ucrm->patched[0]['endpoint']);
        self::assertSame(['clientId' => 42, 'sendReceipt' => false], $ucrm->patched[0]['data']);
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

    public function testAttachClientLogsFailureReasonWhenLoggerProvided(): void
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

        /** @var list<string> $logLines */
        $logLines = [];
        $logger = new Logger(function (string $line) use (&$logLines): void {
            $logLines[] = $line;
        });

        $ok = (new PaymentUpdater($ucrm, $logger))->attachClient(7, 42);

        self::assertFalse($ok);
        self::assertNotSame([], $logLines);
        self::assertStringContainsString('PATCH payments/7/attach failed', $logLines[0]);
    }
}
