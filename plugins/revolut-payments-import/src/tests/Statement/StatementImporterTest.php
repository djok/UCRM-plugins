<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Statement;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Matching\ClientRepository;
use RevolutPaymentsImport\Statement\StatementImporter;
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\IncomingPayment;
use RevolutPaymentsImport\Ucrm\PaymentLookup;
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

    /** @return array{0:StatementImporter,1:CapturingRecorder,2:IdempotencyStore,3:CountingLookup} */
    private function makeImporter(?array $matchedClient, bool $existingPayment = false): array
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
        $lookup = new CountingLookup($existingPayment);
        $importer = new StatementImporter($clients, $recorder, $lookup, $store, new Logger(static fn (string $l) => null));

        return [$importer, $recorder, $store, $lookup];
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
        [$importer, $recorder, , $lookup] = $this->makeImporter(null);

        $imported = $importer->import([$this->row('st-2')]);

        self::assertSame(1, $imported);
        self::assertNull($recorder->records[0]->clientId);
        // No client → the manual-payment check cannot apply.
        self::assertSame(0, $lookup->calls);
    }

    public function testSkipsRowWhenClientAlreadyHasSameAmountPaymentThatDay(): void
    {
        [$importer, $recorder, $store, $lookup] = $this->makeImporter(['id' => 42], existingPayment: true);

        $imported = $importer->import([$this->row('st-3')]);

        self::assertSame(0, $imported);
        self::assertCount(0, $recorder->records);
        self::assertSame(1, $lookup->calls);
        // Decision is final — the row must not come back on a re-run.
        self::assertTrue($store->isProcessed('st-3'));
    }
}

final class CountingLookup implements PaymentLookup
{
    public int $calls = 0;

    public function __construct(private readonly bool $exists)
    {
    }

    public function clientHasPaymentOn(int $clientId, string $dateYmd, float $amount): bool
    {
        $this->calls++;

        return $this->exists;
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
