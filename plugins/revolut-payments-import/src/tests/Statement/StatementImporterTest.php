<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Statement;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Matching\ClientRepository;
use RevolutPaymentsImport\Statement\StatementImporter;
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\IncomingPayment;
use RevolutPaymentsImport\Ucrm\PaymentRecorder;

final class StatementImporterTest extends TestCase
{
    private string $storePath;

    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . '/revolut-si-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->storePath);
    }

    /** @return array{0:StatementImporter,1:CapturingRecorder,2:IdempotencyStore} */
    private function makeImporter(?array $matchedClient): array
    {
        $clients = new class($matchedClient) implements ClientRepository {
            /** @var list<string> */
            public array $askedIbans = [];

            public function __construct(private ?array $client)
            {
            }

            public function findClientByIban(string $iban): ?array
            {
                $this->askedIbans[] = $iban;

                return $this->client;
            }
        };
        $recorder = new CapturingRecorder();
        $store = new IdempotencyStore($this->storePath);
        $importer = new StatementImporter($clients, $recorder, $store, new Logger(static fn (string $l) => null));

        return [$importer, $recorder, $store];
    }

    /** @return array{id:string,date:string,amount:float,currency:string,reference:string,senderName:string,senderIban:string} */
    private function row(string $id = 'st-1'): array
    {
        return [
            'id' => $id,
            'date' => '2026-05-29',
            'amount' => 8.86,
            'currency' => 'EUR',
            'reference' => 'F-RA 1001',
            'senderName' => 'ACME LTD',
            'senderIban' => 'BG00TEST80001000000001',
        ];
    }

    public function testImportsAssignedPaymentWithHistoricalDate(): void
    {
        [$importer, $recorder] = $this->makeImporter(['id' => 42]);

        $imported = $importer->import([$this->row()]);

        self::assertSame(1, $imported);
        $payment = $recorder->records[0];
        self::assertSame(8.86, $payment->amount);
        self::assertSame(42, $payment->clientId);
        self::assertSame('st-1', $payment->externalId);
        self::assertSame('2026-05-29T00:00:00Z', $payment->createdDate);
        self::assertStringContainsString('ACME LTD', $payment->note);
        self::assertStringContainsString('BG00TEST80001000000001', $payment->note);
    }

    public function testSkipsAlreadyProcessedRows(): void
    {
        [$importer, $recorder, $store] = $this->makeImporter(['id' => 42]);
        $store->markProcessed('st-1');

        $imported = $importer->import([$this->row()]);

        self::assertSame(0, $imported);
        self::assertCount(0, $recorder->records);
    }

    public function testUnmatchedIbanImportsUnassigned(): void
    {
        [$importer, $recorder] = $this->makeImporter(null);

        $imported = $importer->import([$this->row('st-2')]);

        self::assertSame(1, $imported);
        self::assertNull($recorder->records[0]->clientId);
    }
}

final class CapturingRecorder implements PaymentRecorder
{
    /** @var list<IncomingPayment> */
    public array $records = [];

    public function record(IncomingPayment $payment): void
    {
        $this->records[] = $payment;
    }
}
