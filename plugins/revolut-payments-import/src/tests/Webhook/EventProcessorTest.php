<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Matching\ClientMatcher;
use RevolutPaymentsImport\Matching\ClientRepository;
use RevolutPaymentsImport\Revolut\CounterpartySource;
use RevolutPaymentsImport\Revolut\TransactionSource;
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\IncomingPayment;
use RevolutPaymentsImport\Ucrm\PaymentRecorder;
use RevolutPaymentsImport\Webhook\EventProcessor;

final class EventProcessorTest extends TestCase
{
    private string $storePath;

    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . '/revolut-ep-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->storePath);
    }

    /** @param array<string,array<mixed>> $transactions @param array<string,array<mixed>> $counterparties */
    private function makeProcessor(
        array $transactions,
        array $counterparties,
        ?array $matchedClient,
        RecordingRecorder $recorder,
        array $allowedAccountIds = [],
    ): EventProcessor {
        $txSource = new class($transactions) implements TransactionSource {
            public function __construct(private array $map)
            {
            }
            public function getTransaction(string $id): ?array
            {
                return $this->map[$id] ?? null;
            }
        };
        $cpSource = new class($counterparties) implements CounterpartySource {
            public function __construct(private array $map)
            {
            }
            public function getCounterparty(string $id): ?array
            {
                return $this->map[$id] ?? null;
            }
        };
        $clients = new class($matchedClient) implements ClientRepository {
            public function __construct(private ?array $client)
            {
            }
            public function findClientByIban(string $iban): ?array
            {
                return $this->client;
            }
        };

        return new EventProcessor(
            $txSource,
            $cpSource,
            $clients,
            $recorder,
            new IdempotencyStore($this->storePath),
            new Logger(static fn (string $l) => null),
            $allowedAccountIds,
        );
    }

    /** @return array<mixed> */
    private function completedIncoming(): array
    {
        return [
            'id' => 'tx-1',
            'type' => 'transfer',
            'state' => 'completed',
            'completed_at' => '2026-02-03T10:15:00Z',
            'reference' => 'Invoice 2601000519',
            'legs' => [[
                'leg_id' => 'leg-1',
                'account_id' => 'acc-main',
                'amount' => 12.44,
                'currency' => 'EUR',
                'counterparty' => ['id' => 'cp-1', 'account_type' => 'external'],
            ]],
        ];
    }

    public function testRecordsAssignedPaymentForCompletedIncoming(): void
    {
        $recorder = new RecordingRecorder();
        $processor = $this->makeProcessor(
            ['tx-1' => $this->completedIncoming()],
            ['cp-1' => ['id' => 'cp-1', 'name' => 'John Doe', 'accounts' => [['iban' => 'BG80BNBG96611020345678']]]],
            ['id' => 77],
            $recorder,
        );

        $processor->processEvent(['event' => 'TransactionStateChanged', 'data' => ['id' => 'tx-1']]);

        self::assertCount(1, $recorder->records);
        $payment = $recorder->records[0];
        self::assertSame(12.44, $payment->amount);
        self::assertSame('EUR', $payment->currencyCode);
        self::assertSame(77, $payment->clientId);
        self::assertSame('tx-1', $payment->externalId);
        self::assertSame('2026-02-03T10:15:00Z', $payment->createdDate);
        self::assertStringContainsString('John Doe', $payment->note);
        self::assertStringContainsString('Invoice 2601000519', $payment->note);
    }

    public function testDoesNotRecordTwice(): void
    {
        $recorder = new RecordingRecorder();
        $processor = $this->makeProcessor(
            ['tx-1' => $this->completedIncoming()],
            ['cp-1' => ['id' => 'cp-1', 'name' => 'John', 'accounts' => [['iban' => 'BG80BNBG96611020345678']]]],
            ['id' => 77],
            $recorder,
        );

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-1']]);
        $processor->processEvent(['event' => 'TransactionStateChanged', 'data' => ['id' => 'tx-1']]);

        self::assertCount(1, $recorder->records);
    }

    public function testSkipsNonCompletedWithoutMarkingProcessed(): void
    {
        $recorder = new RecordingRecorder();
        $pending = $this->completedIncoming();
        $pending['state'] = 'pending';
        $processor = $this->makeProcessor(['tx-1' => $pending], [], null, $recorder);

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-1']]);

        self::assertCount(0, $recorder->records);
        // After it later completes, it must be recorded:
        $completed = $this->completedIncoming();
        $recorder2 = new RecordingRecorder();
        $processor2 = $this->makeProcessor(
            ['tx-1' => $completed],
            ['cp-1' => ['id' => 'cp-1', 'accounts' => [['iban' => 'BG80BNBG96611020345678']]]],
            ['id' => 5],
            $recorder2,
        );
        $processor2->processEvent(['event' => 'TransactionStateChanged', 'data' => ['id' => 'tx-1']]);
        self::assertCount(1, $recorder2->records);
    }

    public function testSkipsOutgoingLeg(): void
    {
        $recorder = new RecordingRecorder();
        $outgoing = $this->completedIncoming();
        $outgoing['legs'][0]['amount'] = -10.0;
        $processor = $this->makeProcessor(['tx-1' => $outgoing], [], null, $recorder);

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-1']]);

        self::assertCount(0, $recorder->records);
    }

    public function testRecordsUnassignedWhenNoIbanOrNoClient(): void
    {
        $recorder = new RecordingRecorder();
        $tx = $this->completedIncoming();
        $processor = $this->makeProcessor(
            ['tx-1' => $tx],
            ['cp-1' => ['id' => 'cp-1', 'name' => 'Unknown', 'accounts' => []]], // no IBAN
            null,
            $recorder,
        );

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-1']]);

        self::assertCount(1, $recorder->records);
        self::assertNull($recorder->records[0]->clientId);
    }

    public function testNoteFallsBackToLegDescriptionWhenCounterpartyUnknown(): void
    {
        $recorder = new RecordingRecorder();
        $tx = $this->completedIncoming();
        unset($tx['legs'][0]['counterparty']); // unknown external sender — no counterparty at all
        $tx['legs'][0]['description'] = 'Payment from MEKS EOOD';
        $processor = $this->makeProcessor(['tx-1' => $tx], [], null, $recorder);

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-1']]);

        self::assertCount(1, $recorder->records);
        $payment = $recorder->records[0];
        self::assertNull($payment->clientId);
        self::assertStringContainsString('MEKS EOOD', $payment->note);
        self::assertStringContainsString('Invoice 2601000519', $payment->note);
    }

    public function testFallsBackToCounterpartyAccountNumberWhenIbanMissing(): void
    {
        $recorder = new RecordingRecorder();
        $processor = $this->makeProcessor(
            ['tx-1' => $this->completedIncoming()],
            ['cp-1' => ['id' => 'cp-1', 'name' => 'EVO', 'accounts' => [['account_no' => 'EVP5610010009412']]]],
            ['id' => 9],
            $recorder,
        );

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-1']]);

        self::assertCount(1, $recorder->records);
        $payment = $recorder->records[0];
        self::assertSame(9, $payment->clientId); // matching got the account number
        self::assertStringContainsString('EVP5610010009412', $payment->note);
    }

    public function testSkipsTransactionFromUnselectedAccount(): void
    {
        $recorder = new RecordingRecorder();
        $processor = $this->makeProcessor(
            ['tx-1' => $this->completedIncoming()], // leg on 'acc-main'
            [],
            null,
            $recorder,
            ['acc-other'], // only this account is selected
        );

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-1']]);

        self::assertCount(0, $recorder->records);
    }

    public function testProcessesTransactionFromSelectedAccount(): void
    {
        $recorder = new RecordingRecorder();
        $processor = $this->makeProcessor(
            ['tx-1' => $this->completedIncoming()],
            ['cp-1' => ['id' => 'cp-1', 'name' => 'John', 'accounts' => [['iban' => 'BG80BNBG96611020345678']]]],
            ['id' => 5],
            $recorder,
            ['ACC-MAIN'], // case-insensitive match against leg account_id
        );

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-1']]);

        self::assertCount(1, $recorder->records);
    }

    public function testSkipsMissingTransactionId(): void
    {
        $recorder = new RecordingRecorder();
        $processor = $this->makeProcessor([], [], null, $recorder);

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => []]);

        self::assertCount(0, $recorder->records);
    }

    public function testMatchesClientBySenderNameWhenIbanMissing(): void
    {
        $recorder = new RecordingRecorder();
        $txSource = new class(['tx-name' => [
            'id' => 'tx-name',
            'state' => 'completed',
            'type' => 'topup',
            'legs' => [[
                'amount' => 49.08,
                'currency' => 'EUR',
                'description' => 'Payment from Astreya 91 Ood',
            ]],
        ]]) implements TransactionSource {
            public function __construct(private array $map)
            {
            }
            public function getTransaction(string $id): ?array
            {
                return $this->map[$id] ?? null;
            }
        };
        $cpSource = new class([]) implements CounterpartySource {
            public function __construct(private array $map)
            {
            }
            public function getCounterparty(string $id): ?array
            {
                return $this->map[$id] ?? null;
            }
        };
        // Client repository that knows no IBANs but recognizes the sender name
        // stored as a bank-account entry (Paysera model).
        $clients = new class implements ClientRepository {
            public function findClientByIban(string $iban): ?array
            {
                return ClientMatcher::normalizeIban($iban) === 'ASTREYA91OOD' ? ['id' => 42] : null;
            }
        };

        $processor = new EventProcessor(
            $txSource,
            $cpSource,
            $clients,
            $recorder,
            new IdempotencyStore($this->storePath),
            new Logger(static fn (string $l) => null),
        );

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-name']]);

        self::assertCount(1, $recorder->records);
        self::assertSame(42, $recorder->records[0]->clientId);
    }

    public function testIbanMatchTakesPrecedenceOverName(): void
    {
        $recorder = new RecordingRecorder();
        $tx = $this->completedIncoming();
        $tx['legs'][0]['description'] = 'Payment from Astreya 91 Ood';
        $txSource = new class(['tx-1' => $tx]) implements TransactionSource {
            public function __construct(private array $map)
            {
            }
            public function getTransaction(string $id): ?array
            {
                return $this->map[$id] ?? null;
            }
        };
        $cpSource = new class(['cp-1' => ['id' => 'cp-1', 'name' => 'Astreya 91 Ood', 'accounts' => [['iban' => 'BG80BNBG96611020345678']]]]) implements CounterpartySource {
            public function __construct(private array $map)
            {
            }
            public function getCounterparty(string $id): ?array
            {
                return $this->map[$id] ?? null;
            }
        };
        // IBAN candidate resolves to client 1; name candidate resolves to client 2.
        // The IBAN must win.
        $clients = new class implements ClientRepository {
            public function findClientByIban(string $iban): ?array
            {
                $normalized = ClientMatcher::normalizeIban($iban);
                if ($normalized === ClientMatcher::normalizeIban('BG80BNBG96611020345678')) {
                    return ['id' => 1];
                }
                if ($normalized === 'ASTREYA91OOD') {
                    return ['id' => 2];
                }

                return null;
            }
        };

        $processor = new EventProcessor(
            $txSource,
            $cpSource,
            $clients,
            $recorder,
            new IdempotencyStore($this->storePath),
            new Logger(static fn (string $l) => null),
        );

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-1']]);

        self::assertCount(1, $recorder->records);
        self::assertSame(1, $recorder->records[0]->clientId);
    }

    public function testDeclinedTransactionIsTerminalAndMarkedProcessed(): void
    {
        $recorder = new RecordingRecorder();
        $declined = $this->completedIncoming();
        $declined['state'] = 'declined';
        $processor = $this->makeProcessor(['tx-1' => $declined], [], null, $recorder);

        $processor->processEvent(['event' => 'TransactionStateChanged', 'data' => ['id' => 'tx-1', 'new_state' => 'declined']]);
        self::assertCount(0, $recorder->records);

        // Marked terminal: even if the SAME id later appears completed, it is skipped
        // (declined/failed/reverted never become a payment). Same store path on disk.
        $recorder2 = new RecordingRecorder();
        $processor2 = $this->makeProcessor(
            ['tx-1' => $this->completedIncoming()],
            ['cp-1' => ['id' => 'cp-1', 'accounts' => [['iban' => 'BG80BNBG96611020345678']]]],
            ['id' => 5],
            $recorder2,
        );
        $processor2->processEvent(['event' => 'TransactionStateChanged', 'data' => ['id' => 'tx-1']]);
        self::assertCount(0, $recorder2->records);
    }

    public function testFailedTransactionIsTerminal(): void
    {
        $recorder = new RecordingRecorder();
        $failed = $this->completedIncoming();
        $failed['state'] = 'failed';
        $processor = $this->makeProcessor(['tx-1' => $failed], [], null, $recorder);

        $processor->processEvent(['event' => 'TransactionStateChanged', 'data' => ['id' => 'tx-1']]);

        self::assertCount(0, $recorder->records);
    }

    public function testRevertedAfterRecordedLogsErrorAndKeepsSinglePayment(): void
    {
        $lines = [];
        $logger = new Logger(function (string $l) use (&$lines): void {
            $lines[] = $l;
        });
        $recorder = new RecordingRecorder();
        $store = new IdempotencyStore($this->storePath);

        $completed = $this->completedIncoming();
        $reverted = $this->completedIncoming();
        $reverted['state'] = 'reverted';

        $txSource = new class(['tx-1' => $completed]) implements TransactionSource {
            public function __construct(private array $map)
            {
            }
            public function getTransaction(string $id): ?array
            {
                return $this->map[$id] ?? null;
            }
        };
        $cpSource = new class(['cp-1' => ['id' => 'cp-1', 'name' => 'X', 'accounts' => [['iban' => 'BG80BNBG96611020345678']]]]) implements CounterpartySource {
            public function __construct(private array $map)
            {
            }
            public function getCounterparty(string $id): ?array
            {
                return $this->map[$id] ?? null;
            }
        };
        $clients = new class implements ClientRepository {
            public function findClientByIban(string $iban): ?array
            {
                return ['id' => 3];
            }
        };

        $processor = new EventProcessor($txSource, $cpSource, $clients, $recorder, $store, $logger);

        $processor->processTransaction($completed);
        self::assertCount(1, $recorder->records);

        // Now Revolut reverts the same transaction after we recorded it — twice
        // (reconciliation + replay re-observe it). The alert must fire only ONCE.
        $processor->processTransaction($reverted);
        $processor->processTransaction($reverted);
        $processor->processEvent(['data' => ['id' => 'tx-1', 'new_state' => 'reverted']]);
        self::assertCount(1, $recorder->records, 'no second payment for a reversal');
        self::assertCount(
            1,
            array_filter($lines, static fn (string $l): bool => str_contains($l, 'REVERTED')),
            'the manual-reversal alert must be logged at most once',
        );
    }

    public function testProcessedIdStateChangeDoesNotRefetchTransaction(): void
    {
        $recorder = new RecordingRecorder();
        $store = new IdempotencyStore($this->storePath);

        $txSource = new class(['tx-1' => $this->completedIncoming()]) implements TransactionSource {
            public int $calls = 0;
            public function __construct(private array $map)
            {
            }
            public function getTransaction(string $id): ?array
            {
                $this->calls++;

                return $this->map[$id] ?? null;
            }
        };
        $cpSource = new class(['cp-1' => ['id' => 'cp-1', 'accounts' => [['iban' => 'BG80BNBG96611020345678']]]]) implements CounterpartySource {
            public function __construct(private array $map)
            {
            }
            public function getCounterparty(string $id): ?array
            {
                return $this->map[$id] ?? null;
            }
        };
        $clients = new class implements ClientRepository {
            public function findClientByIban(string $iban): ?array
            {
                return ['id' => 9];
            }
        };
        $logger = new Logger(static fn (string $l) => null);

        $processor = new EventProcessor($txSource, $cpSource, $clients, $recorder, $store, $logger);

        $processor->processEvent(['event' => 'TransactionCreated', 'data' => ['id' => 'tx-1']]);
        self::assertSame(1, $txSource->calls);
        self::assertCount(1, $recorder->records);

        // A later state-change event for the already-processed id must NOT hit Revolut.
        $processor->processEvent(['event' => 'TransactionStateChanged', 'data' => ['id' => 'tx-1', 'new_state' => 'completed']]);
        self::assertSame(1, $txSource->calls, 'no refetch for an already-processed id');
        self::assertCount(1, $recorder->records);
    }
}

final class RecordingRecorder implements PaymentRecorder
{
    /** @var array<int,IncomingPayment> */
    public array $records = [];

    public function record(IncomingPayment $payment): void
    {
        $this->records[] = $payment;
    }
}
