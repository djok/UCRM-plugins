<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Statement;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Matching\ClientMatcher;
use RevolutPaymentsImport\Matching\ClientRepository;
use RevolutPaymentsImport\Statement\StatementReMatcher;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\AccountLearner;
use RevolutPaymentsImport\Ucrm\PaymentFinderInterface;
use RevolutPaymentsImport\Ucrm\PaymentUpdaterInterface;

final class StatementReMatcherTest extends TestCase
{
    /** @var list<string> */
    private array $logLines = [];

    /** @param array<string,mixed>|null $payment */
    private function finderReturning(?array $payment): PaymentFinderInterface
    {
        return new class($payment) implements PaymentFinderInterface {
            /** @param array<string,mixed>|null $payment */
            public function __construct(private readonly ?array $payment)
            {
            }

            public function findForStatementRow(string $transactionId, string $dateYmd, float $amount): ?array
            {
                return $this->payment;
            }
        };
    }

    private function updaterSpy(bool $result): PaymentUpdaterInterface
    {
        return new class($result) implements PaymentUpdaterInterface {
            /** @var list<array{0:int,1:int}> */
            public array $attached = [];

            public function __construct(private readonly bool $result)
            {
            }

            public function attachClient(int $paymentId, int $clientId): bool
            {
                $this->attached[] = [$paymentId, $clientId];

                return $this->result;
            }
        };
    }

    /** @param array<string,array<string,mixed>> $byIban */
    private function clientsByIban(array $byIban): ClientRepository
    {
        return new class($byIban) implements ClientRepository {
            /** @param array<string,array<string,mixed>> $byIban */
            public function __construct(private readonly array $byIban)
            {
            }

            public function findClientByIban(string $iban): ?array
            {
                $key = ClientMatcher::normalizeIban($iban);
                foreach ($this->byIban as $candidate => $client) {
                    if (ClientMatcher::normalizeIban($candidate) === $key) {
                        return $client;
                    }
                }

                return null;
            }
        };
    }

    private function learnerSpy(): AccountLearner
    {
        return new class implements AccountLearner {
            /** @var list<array{0:int,1:array<int,string>}> */
            public array $learned = [];

            public function learn(int $clientId, array $accountNumbers): void
            {
                $this->learned[] = [$clientId, $accountNumbers];
            }
        };
    }

    private function logger(): Logger
    {
        return new Logger(function (string $line): void {
            $this->logLines[] = $line;
        });
    }

    public function testAttachesUnassignedPaymentByIbanAndLearns(): void
    {
        $finder = $this->finderReturning(['id' => 7, 'clientId' => null]);
        $updater = $this->updaterSpy(true);
        $clients = $this->clientsByIban(['BG47UNCR70001521149247' => ['id' => 42]]);
        $learner = $this->learnerSpy();

        $reMatcher = new StatementReMatcher($finder, $updater, $clients, $learner, $this->logger(), true);
        $reMatcher->reMatch([
            'id' => 'tx-1', 'date' => '2026-06-30', 'amount' => 50.42, 'currency' => 'EUR',
            'reference' => 'invoice 2606000798', 'senderName' => 'Hadzhiradevi Ood', 'senderIban' => 'BG47UNCR70001521149247',
        ]);

        self::assertSame([[7, 42]], $updater->attached);
        self::assertSame([[42, ['BG47UNCR70001521149247', 'Hadzhiradevi Ood']]], $learner->learned);
    }

    public function testPaymentNotFoundDoesNothing(): void
    {
        $finder = $this->finderReturning(null);
        $updater = $this->updaterSpy(true);
        $clients = $this->clientsByIban(['BG47UNCR70001521149247' => ['id' => 42]]);
        $learner = $this->learnerSpy();

        $reMatcher = new StatementReMatcher($finder, $updater, $clients, $learner, $this->logger(), true);
        $reMatcher->reMatch([
            'id' => 'tx-2', 'date' => '2026-06-30', 'amount' => 50.42, 'currency' => 'EUR',
            'reference' => 'invoice 2606000799', 'senderName' => 'Hadzhiradevi Ood', 'senderIban' => 'BG47UNCR70001521149247',
        ]);

        self::assertSame([], $updater->attached);
        self::assertSame([], $learner->learned);
    }

    public function testUnassignedPaymentMatchedByNameWhenIbanUnknownAttachesAndLearnsSameIdentities(): void
    {
        $finder = $this->finderReturning(['id' => 9, 'clientId' => null]);
        $updater = $this->updaterSpy(true);
        $clients = $this->clientsByIban(['Hadzhiradevi Ood' => ['id' => 42]]);
        $learner = $this->learnerSpy();

        $reMatcher = new StatementReMatcher($finder, $updater, $clients, $learner, $this->logger(), true);
        $reMatcher->reMatch([
            'id' => 'tx-3', 'date' => '2026-06-30', 'amount' => 50.42, 'currency' => 'EUR',
            'reference' => 'invoice 2606000800', 'senderName' => 'Hadzhiradevi Ood', 'senderIban' => 'BG00UNKNOWN0000000000',
        ]);

        self::assertSame([[9, 42]], $updater->attached);
        self::assertSame([[42, ['BG00UNKNOWN0000000000', 'Hadzhiradevi Ood']]], $learner->learned);
    }

    public function testUnassignedPaymentNothingMatchesLogsSkipAndDoesNotAttach(): void
    {
        $finder = $this->finderReturning(['id' => 11, 'clientId' => null]);
        $updater = $this->updaterSpy(true);
        $clients = $this->clientsByIban([]);
        $learner = $this->learnerSpy();

        $reMatcher = new StatementReMatcher($finder, $updater, $clients, $learner, $this->logger(), true);
        $reMatcher->reMatch([
            'id' => 'tx-4', 'date' => '2026-06-30', 'amount' => 50.42, 'currency' => 'EUR',
            'reference' => 'invoice 2606000801', 'senderName' => 'Unknown Sender', 'senderIban' => 'BG00UNKNOWN0000000001',
        ]);

        self::assertSame([], $updater->attached);
        self::assertSame([], $learner->learned);
        self::assertStringContainsString('no client recognized', $this->logLines[0]);
    }

    public function testAlreadyAssignedPaymentWithLearnSendersLearnsOnlyNoAttach(): void
    {
        $finder = $this->finderReturning(['id' => 13, 'clientId' => 42]);
        $updater = $this->updaterSpy(true);
        // No client resolves from the sender identity here — proves learning still binds
        // to the payment's existing client (42) even without an independent client match.
        $clients = $this->clientsByIban([]);
        $learner = $this->learnerSpy();

        $reMatcher = new StatementReMatcher($finder, $updater, $clients, $learner, $this->logger(), true);
        $reMatcher->reMatch([
            'id' => 'tx-5', 'date' => '2026-06-30', 'amount' => 50.42, 'currency' => 'EUR',
            'reference' => 'invoice 2606000802', 'senderName' => 'Hadzhiradevi Ood', 'senderIban' => 'BG47UNCR70001521149247',
        ]);

        self::assertSame([], $updater->attached);
        self::assertSame([[42, ['BG47UNCR70001521149247', 'Hadzhiradevi Ood']]], $learner->learned);
    }

    public function testAlreadyAssignedPaymentWithConflictingIdentityDoesNotLearnAndLogsConflict(): void
    {
        $finder = $this->finderReturning(['id' => 14, 'clientId' => 42]);
        $updater = $this->updaterSpy(true);
        // Sender identity resolves to a DIFFERENT client (99) than the one already on the payment (42).
        $clients = $this->clientsByIban(['BG47UNCR70001521149247' => ['id' => 99]]);
        $learner = $this->learnerSpy();

        $reMatcher = new StatementReMatcher($finder, $updater, $clients, $learner, $this->logger(), true);
        $reMatcher->reMatch([
            'id' => 'tx-5b', 'date' => '2026-06-30', 'amount' => 50.42, 'currency' => 'EUR',
            'reference' => 'invoice 2606000802b', 'senderName' => 'Hadzhiradevi Ood', 'senderIban' => 'BG47UNCR70001521149247',
        ]);

        self::assertSame([], $updater->attached);
        self::assertSame([], $learner->learned);
        self::assertNotSame([], $this->logLines);
        self::assertStringContainsString('conflict', $this->logLines[0]);
    }

    public function testAttachFailureLogsWarningButLearningStillRuns(): void
    {
        $finder = $this->finderReturning(['id' => 15, 'clientId' => null]);
        $updater = $this->updaterSpy(false);
        $clients = $this->clientsByIban(['BG47UNCR70001521149247' => ['id' => 42]]);
        $learner = $this->learnerSpy();

        $reMatcher = new StatementReMatcher($finder, $updater, $clients, $learner, $this->logger(), true);
        $reMatcher->reMatch([
            'id' => 'tx-6', 'date' => '2026-06-30', 'amount' => 50.42, 'currency' => 'EUR',
            'reference' => 'invoice 2606000803', 'senderName' => 'Hadzhiradevi Ood', 'senderIban' => 'BG47UNCR70001521149247',
        ]);

        self::assertSame([[15, 42]], $updater->attached);
        self::assertSame([[42, ['BG47UNCR70001521149247', 'Hadzhiradevi Ood']]], $learner->learned);
        self::assertNotSame([], $this->logLines);
        self::assertStringContainsString('ERROR', $this->logLines[0]);
    }

    public function testFinderExceptionIsIsolatedAndLogged(): void
    {
        $finder = new class implements PaymentFinderInterface {
            public function findForStatementRow(string $transactionId, string $dateYmd, float $amount): ?array
            {
                throw new \RuntimeException('boom');
            }
        };
        $updater = $this->updaterSpy(true);
        $clients = $this->clientsByIban(['BG47UNCR70001521149247' => ['id' => 42]]);
        $learner = $this->learnerSpy();

        $reMatcher = new StatementReMatcher($finder, $updater, $clients, $learner, $this->logger(), true);
        $reMatcher->reMatch([
            'id' => 'tx-8', 'date' => '2026-06-30', 'amount' => 50.42, 'currency' => 'EUR',
            'reference' => 'invoice 2606000805', 'senderName' => 'Hadzhiradevi Ood', 'senderIban' => 'BG47UNCR70001521149247',
        ]);

        self::assertSame([], $updater->attached);
        self::assertSame([], $learner->learned);
        self::assertNotSame([], $this->logLines);
        self::assertStringContainsString('failed', $this->logLines[0]);
    }

    public function testLearnSendersDisabledAttachesButNeverLearns(): void
    {
        $finder = $this->finderReturning(['id' => 17, 'clientId' => null]);
        $updater = $this->updaterSpy(true);
        $clients = $this->clientsByIban(['BG47UNCR70001521149247' => ['id' => 42]]);
        $learner = $this->learnerSpy();

        $reMatcher = new StatementReMatcher($finder, $updater, $clients, $learner, $this->logger(), false);
        $reMatcher->reMatch([
            'id' => 'tx-7', 'date' => '2026-06-30', 'amount' => 50.42, 'currency' => 'EUR',
            'reference' => 'invoice 2606000804', 'senderName' => 'Hadzhiradevi Ood', 'senderIban' => 'BG47UNCR70001521149247',
        ]);

        self::assertSame([[17, 42]], $updater->attached);
        self::assertSame([], $learner->learned);
    }
}
