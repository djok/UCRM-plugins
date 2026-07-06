# Revolut Sender-Identity Matching (v1.8.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Payments whose sender has no API-visible IBAN are matched to clients by the **sender name** stored as an extra `bankAccounts` entry on the UISP client; the statement CSV upload additionally attaches existing unassigned payments and auto-learns sender identities.

**Architecture:** The sender name is fed through the existing IBAN-matching channel (`ClientMatcher::findClientByIban`, which normalizes uppercase + strips whitespace on both sides — Paysera model). New small UCRM adapters (`PaymentUpdater`, `ClientAccountLearner`, `PaymentFinder`) handle attach/learn via `PATCH`; a new `StatementReMatcher` walks already-processed statement rows. All matching logic stays in unit-testable classes.

**Tech Stack:** PHP 8.1 (strict types, final classes, readonly), PHPUnit 10.5, UCRM Plugin SDK (`UcrmApi` has a `patch()` method). No new composer dependencies.

**Spec:** `docs/superpowers/specs/2026-07-06-revolut-sender-identity-design.md`

## Global Constraints

- Plugin root: `plugins/revolut-payments-import/src`; PHP paths below are relative to it.
- **No local PHP.** Every command runs via Docker through the **PowerShell tool**:
  `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 <command>`
- Code style: `declare(strict_types=1);`, PSR-12, `final` classes, `readonly` properties, English comments; UI strings Bulgarian.
- Matching order everywhere: sender IBAN → sender name → unassigned. ONE normalization (`ClientMatcher::normalizeIban`: uppercase + strip whitespace) — no new normalization functions.
- PATCH failures degrade gracefully: log, continue, never abort a run.
- Learning default: **enabled** unless config value is explicitly `false`/`0`/`'0'`/`'false'`.
- Commits: `<type>(revolut): <description>` — no attribution footers.
- New version: **1.8.0**.

---

### Task 1: UcrmClient::patch + adapters PaymentUpdater and PaymentFinder

**Files:**
- Modify: `src/Ucrm/UcrmClient.php`, `src/Ucrm/SdkUcrmClient.php`
- Modify (add `patch` stub to UcrmClient test doubles): `tests/Ucrm/UcrmPaymentGatewayTest.php` (two anonymous classes, lines ~15 and ~145), `tests/Ucrm/UcrmPaymentLookupTest.php` (~15), `tests/Matching/ClientMatcherTest.php` (~24)
- Create: `src/Ucrm/PaymentUpdater.php`, `src/Ucrm/PaymentFinder.php`
- Test: `tests/Ucrm/PaymentUpdaterTest.php`, `tests/Ucrm/PaymentFinderTest.php`

**Interfaces:**
- Produces: `UcrmClient::patch(string $endpoint, array $data): array`;
  `PaymentUpdater::attachClient(int $paymentId, int $clientId): bool`;
  `PaymentFinder::findForStatementRow(string $transactionId, string $dateYmd, float $amount): ?array` (returns the raw payment array or null).

- [ ] **Step 1: Extend the interface and SDK client** — in `src/Ucrm/UcrmClient.php` add below `post()`:

```php
    /**
     * @param array<string,mixed> $data
     * @return array<mixed>
     */
    public function patch(string $endpoint, array $data): array;
```

In `src/Ucrm/SdkUcrmClient.php` add below `post()`:

```php
    public function patch(string $endpoint, array $data): array
    {
        $result = $this->api->patch($endpoint, $data);

        return is_array($result) ? $result : [];
    }
```

- [ ] **Step 2: Fix the now-broken test doubles** — run the suite; every anonymous `implements UcrmClient` class fails to compile. Add to each (same shape in all four):

```php
            public function patch(string $endpoint, array $data): array
            {
                return [];
            }
```

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit`
Expected: PASS (83 tests — same as before).

- [ ] **Step 3: Write failing tests for the two adapters** — create `tests/Ucrm/PaymentUpdaterTest.php`:

```php
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
```

Create `tests/Ucrm/PaymentFinderTest.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Ucrm;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Ucrm\PaymentFinder;
use RevolutPaymentsImport\Ucrm\UcrmClient;

final class PaymentFinderTest extends TestCase
{
    /** @param list<array<string,mixed>> $payments */
    private function ucrm(array $payments): UcrmClient
    {
        return new class($payments) implements UcrmClient {
            /** @var array<string,scalar>|null */
            public ?array $lastParams = null;

            /** @param list<array<string,mixed>> $payments */
            public function __construct(private readonly array $payments)
            {
            }

            public function get(string $endpoint, array $params = []): array
            {
                $this->lastParams = $params;

                return $this->payments;
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

    public function testFindsByProviderPaymentId(): void
    {
        $payment = ['id' => 5, 'providerName' => 'Revolut', 'providerPaymentId' => 'tx-1', 'amount' => 999.0, 'note' => 'x', 'createdDate' => '2026-06-15T00:00:00+03:00'];

        $found = (new PaymentFinder($this->ucrm([$payment])))->findForStatementRow('tx-1', '2026-06-15', 8.86);

        self::assertSame(5, $found['id']);
    }

    public function testFallsBackToLegacyNoteAmountDateHeuristic(): void
    {
        $legacy = ['id' => 6, 'providerPaymentId' => null, 'note' => 'Revolut: someone', 'amount' => 8.86, 'createdDate' => '2026-06-15T10:00:00+03:00'];

        $found = (new PaymentFinder($this->ucrm([$legacy])))->findForStatementRow('tx-1', '2026-06-15', 8.86);

        self::assertSame(6, $found['id']);
    }

    public function testReturnsNullWhenNothingMatches(): void
    {
        $other = ['id' => 8, 'providerPaymentId' => null, 'note' => 'платено на каса', 'amount' => 8.86, 'createdDate' => '2026-06-15T10:00:00+03:00'];

        self::assertNull((new PaymentFinder($this->ucrm([$other])))->findForStatementRow('tx-1', '2026-06-15', 8.86));
    }

    public function testQueriesPaddedDateWindow(): void
    {
        $ucrm = $this->ucrm([]);
        (new PaymentFinder($ucrm))->findForStatementRow('tx-1', '2026-06-01', 1.0);

        self::assertSame('2026-05-31', $ucrm->lastParams['createdDateFrom']);
        self::assertSame('2026-06-02', $ucrm->lastParams['createdDateTo']);
    }
}
```

- [ ] **Step 4: Run new tests to verify they fail**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit --filter "PaymentUpdaterTest|PaymentFinderTest"`
Expected: ERROR — classes not found.

- [ ] **Step 5: Implement** — create `src/Ucrm/PaymentUpdater.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

/**
 * Attaches an existing unassigned UISP payment to a client. Kept separate
 * from creation (UcrmPaymentGateway) and intentionally non-throwing: some
 * UISP versions may not allow PATCHing payments — callers log and move on.
 */
final class PaymentUpdater
{
    public function __construct(private readonly UcrmClient $ucrm)
    {
    }

    public function attachClient(int $paymentId, int $clientId): bool
    {
        try {
            $this->ucrm->patch('payments/' . $paymentId, ['clientId' => $clientId]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
```

Create `src/Ucrm/PaymentFinder.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

/**
 * Locates the UISP payment created for a Revolut transaction: exact match by
 * providerPaymentId (stamped since v1.6.0), else the legacy heuristic (note
 * prefix + amount + date). The query window is padded ±1 day because payment
 * createdDate filtering happens in the UISP server's local timezone.
 */
final class PaymentFinder
{
    private const AMOUNT_EPSILON = 0.005;
    private const LEGACY_NOTE_PREFIX = 'Revolut: ';

    public function __construct(private readonly UcrmClient $ucrm)
    {
    }

    /** @return array<mixed>|null */
    public function findForStatementRow(string $transactionId, string $dateYmd, float $amount): ?array
    {
        $from = (new \DateTimeImmutable($dateYmd . 'T00:00:00Z'))->modify('-1 day')->format('Y-m-d');
        $to = (new \DateTimeImmutable($dateYmd . 'T00:00:00Z'))->modify('+1 day')->format('Y-m-d');
        $payments = $this->ucrm->get('payments', [
            'createdDateFrom' => $from,
            'createdDateTo' => $to,
            'limit' => 500,
        ]);

        $legacyCandidate = null;
        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }
            if (
                ($payment['providerName'] ?? null) === UcrmPaymentGateway::PROVIDER_NAME
                && (string) ($payment['providerPaymentId'] ?? '') === $transactionId
            ) {
                return $payment;
            }
            if (
                $legacyCandidate === null
                && str_starts_with((string) ($payment['note'] ?? ''), self::LEGACY_NOTE_PREFIX)
                && abs((float) ($payment['amount'] ?? 0.0) - $amount) < self::AMOUNT_EPSILON
                && substr((string) ($payment['createdDate'] ?? ''), 0, 10) === $dateYmd
            ) {
                $legacyCandidate = $payment;
            }
        }

        return $legacyCandidate;
    }
}
```

- [ ] **Step 6: Run the full suite**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit`
Expected: PASS (89 tests).

- [ ] **Step 7: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Ucrm plugins/revolut-payments-import/src/tests
git commit -m "feat(revolut): UcrmClient::patch, PaymentUpdater and PaymentFinder adapters"
```

---

### Task 2: Sender-name fallback in EventProcessor (+ diagnostics removal)

**Files:**
- Modify: `src/Webhook/EventProcessor.php`
- Test: `tests/Webhook/EventProcessorTest.php`

**Interfaces:**
- Consumes: existing `resolveSender()` returning `['iban' => ?string, 'name' => ?string]`; `ClientRepository::findClientByIban(string)` (the name is passed through the SAME method — one normalization).
- Produces: matching order IBAN → name → unassigned. The `DEBUG transaction without sender IBAN` log line is REMOVED (investigation settled).

- [ ] **Step 1: Write the failing test** — open `tests/Webhook/EventProcessorTest.php`, study how existing tests build the processor and its collaborators (anonymous classes for `TransactionSource`, `CounterpartySource`, `ClientRepository`, `PaymentRecorder`), then add a test following the file's existing fixture style:

```php
    public function testMatchesClientBySenderNameWhenIbanMissing(): void
    {
        // Client repository that knows no IBANs but recognizes the sender name
        // stored as a bank-account entry (Paysera model).
        // Build a completed topup transaction whose leg has NO counterparty and
        // description 'Payment from Astreya 91 Ood', run processTransaction,
        // and assert the recorded payment's clientId is the matched client's.
    }
```

The test body must be real code in the file's existing style: a `ClientRepository` double whose `findClientByIban($value)` returns `['id' => 42]` only when `ClientMatcher::normalizeIban($value) === 'ASTREYA91OOD'`, a transaction fixture `['id' => 'tx-name', 'state' => 'completed', 'type' => 'topup', 'legs' => [['amount' => 49.08, 'currency' => 'EUR', 'description' => 'Payment from Astreya 91 Ood']]]`, and an assertion that the recorded `IncomingPayment->clientId === 42`. Also add the inverse guard test `testIbanMatchTakesPrecedenceOverName` (repository returns client 1 for the IBAN and client 2 for the name; expect 1).

- [ ] **Step 2: Run to verify failure**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit --filter EventProcessorTest`
Expected: FAIL — clientId is null (name never fed to the matcher).

- [ ] **Step 3: Implement** — in `src/Webhook/EventProcessor.php` replace:

```php
        $sender = $this->resolveSender($leg);
        if ($sender['iban'] === null) {
            // Diagnostic: capture what the API actually returns for senders without
            // a resolvable IBAN, so the field mapping can be extended if Revolut
            // exposes the remitter elsewhere. Admin-only log; remove once settled.
            $this->logger->info('DEBUG transaction without sender IBAN: ' . json_encode($transaction, JSON_UNESCAPED_UNICODE));
        }
        $clientId = $this->matchClient($sender['iban']);
```

with:

```php
        $sender = $this->resolveSender($leg);
        // The API exposes no sender IBAN for external transfers (verified),
        // so fall back to the sender NAME through the same matching channel —
        // a name stored as a bankAccounts entry on the client acts as an IBAN.
        $clientId = $this->matchClient($sender['iban']) ?? $this->matchClient($sender['name']);
```

(`matchClient(?string)` already handles null.)

- [ ] **Step 4: Run the full suite** — expected PASS.

- [ ] **Step 5: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Webhook/EventProcessor.php plugins/revolut-payments-import/src/tests/Webhook/EventProcessorTest.php
git commit -m "feat(revolut): match webhook payments by sender name when IBAN is absent"
```

---

### Task 3: ClientAccountLearner

**Files:**
- Create: `src/Ucrm/ClientAccountLearner.php`
- Test: `tests/Ucrm/ClientAccountLearnerTest.php`

**Interfaces:**
- Consumes: `UcrmClient::get('clients/{id}')`, `UcrmClient::patch('clients/{id}', ...)`, `ClientMatcher::normalizeIban()` for dedupe.
- Produces: `ClientAccountLearner::learn(int $clientId, array $accountNumbers): void` — appends missing bankAccounts entries; silent no-op when nothing new; never throws.

- [ ] **Step 1: Write the failing tests** — create `tests/Ucrm/ClientAccountLearnerTest.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Ucrm;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\ClientAccountLearner;
use RevolutPaymentsImport\Ucrm\UcrmClient;

final class ClientAccountLearnerTest extends TestCase
{
    /** @var list<string> */
    private array $logLines = [];

    private function logger(): Logger
    {
        return new Logger(function (string $line): void {
            $this->logLines[] = $line;
        });
    }

    /** @param array<string,mixed> $client */
    private function ucrm(array $client): UcrmClient
    {
        return new class($client) implements UcrmClient {
            /** @var array<int,array{endpoint:string,data:array<string,mixed>}> */
            public array $patched = [];

            /** @param array<string,mixed> $client */
            public function __construct(private readonly array $client)
            {
            }

            public function get(string $endpoint, array $params = []): array
            {
                return $this->client;
            }

            public function post(string $endpoint, array $data): array
            {
                return [];
            }

            public function patch(string $endpoint, array $data): array
            {
                $this->patched[] = ['endpoint' => $endpoint, 'data' => $data];

                return [];
            }
        };
    }

    public function testAppendsOnlyMissingEntriesPreservingExisting(): void
    {
        $ucrm = $this->ucrm(['id' => 42, 'bankAccounts' => [['id' => 1, 'accountNumber' => 'BG47UNCR70001521149247']]]);

        (new ClientAccountLearner($ucrm, $this->logger()))
            ->learn(42, ['bg47 uncr 7000 1521 1492 47', 'Hadzhiradevi Ood']);

        self::assertCount(1, $ucrm->patched);
        self::assertSame('clients/42', $ucrm->patched[0]['endpoint']);
        $accounts = $ucrm->patched[0]['data']['bankAccounts'];
        self::assertSame('BG47UNCR70001521149247', $accounts[0]['accountNumber']);
        self::assertSame('Hadzhiradevi Ood', $accounts[1]['accountNumber']);
        self::assertCount(2, $accounts);
    }

    public function testNoPatchWhenEverythingAlreadyKnown(): void
    {
        $ucrm = $this->ucrm(['id' => 42, 'bankAccounts' => [['accountNumber' => 'HADZHIRADEVIOOD']]]);

        (new ClientAccountLearner($ucrm, $this->logger()))->learn(42, ['Hadzhiradevi Ood', '', null]);

        self::assertSame([], $ucrm->patched);
    }

    public function testApiFailureIsLoggedNotThrown(): void
    {
        $ucrm = new class implements UcrmClient {
            public function get(string $endpoint, array $params = []): array
            {
                return ['id' => 42, 'bankAccounts' => []];
            }

            public function post(string $endpoint, array $data): array
            {
                return [];
            }

            public function patch(string $endpoint, array $data): array
            {
                throw new \RuntimeException('boom');
            }
        };

        (new ClientAccountLearner($ucrm, $this->logger()))->learn(42, ['Somebody Ltd']);

        self::assertNotSame([], $this->logLines);
    }
}
```

- [ ] **Step 2: Run to verify failure** — class not found.

- [ ] **Step 3: Implement** — create `src/Ucrm/ClientAccountLearner.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

use RevolutPaymentsImport\Matching\ClientMatcher;
use RevolutPaymentsImport\Support\Logger;

/**
 * Teaches UISP a client's sender identities: appends the sender IBAN and name
 * as extra bankAccounts entries so future payments match automatically (the
 * matcher compares normalized accountNumbers — Paysera model). Sends the
 * existing entries plus the new ones, deduped, which is safe whether the API
 * PATCH replaces or merges the collection. Best-effort: failures are logged.
 *
 * @see ClientMatcher::normalizeIban()
 */
final class ClientAccountLearner
{
    public function __construct(
        private readonly UcrmClient $ucrm,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<int,string|null> $accountNumbers candidate identities (IBAN, sender name) */
    public function learn(int $clientId, array $accountNumbers): void
    {
        try {
            $client = $this->ucrm->get('clients/' . $clientId);
            $existing = [];
            $payload = [];
            foreach (is_array($client['bankAccounts'] ?? null) ? $client['bankAccounts'] : [] as $account) {
                if (! is_array($account) || ! isset($account['accountNumber'])) {
                    continue;
                }
                $existing[ClientMatcher::normalizeIban((string) $account['accountNumber'])] = true;
                $payload[] = ['accountNumber' => (string) $account['accountNumber']];
            }

            $added = 0;
            foreach ($accountNumbers as $number) {
                $number = trim((string) $number);
                $key = ClientMatcher::normalizeIban($number);
                if ($number === '' || isset($existing[$key])) {
                    continue;
                }
                $existing[$key] = true;
                $payload[] = ['accountNumber' => $number];
                $added++;
            }
            if ($added === 0) {
                return;
            }

            $this->ucrm->patch('clients/' . $clientId, ['bankAccounts' => $payload]);
            $this->logger->info(sprintf('Learner: client %d gained %d sender identit%s.', $clientId, $added, $added === 1 ? 'y' : 'ies'));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Learner: could not update client %d: %s', $clientId, $e->getMessage()));
        }
    }
}
```

- [ ] **Step 4: Run the full suite** — expected PASS.

- [ ] **Step 5: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Ucrm/ClientAccountLearner.php plugins/revolut-payments-import/src/tests/Ucrm/ClientAccountLearnerTest.php
git commit -m "feat(revolut): sender-identity learner appends client bank-account entries"
```

---

### Task 4: StatementReMatcher (attach + learn for processed rows)

**Files:**
- Create: `src/Statement/StatementReMatcher.php`
- Test: `tests/Statement/StatementReMatcherTest.php`

**Interfaces:**
- Consumes: `PaymentFinder::findForStatementRow`, `PaymentUpdater::attachClient`, `ClientRepository::findClientByIban`, `ClientAccountLearner::learn`, `Logger`.
- Produces: `StatementReMatcher::__construct(PaymentFinder, PaymentUpdater, ClientRepository, ClientAccountLearner, Logger, bool $learnSenders)`;
  `reMatch(array $row): void` where `$row` is the parsed statement row shape `{id, date, amount, currency, reference, senderName, senderIban}`.

Behavior matrix (write one test per line):
1. payment not found → nothing happens (no attach, no learn).
2. payment unassigned + IBAN matches client → attach; if `learnSenders` → learn IBAN + name.
3. payment unassigned + IBAN unknown but NAME matches client → attach; learning called with the same identities.
4. payment unassigned + nothing matches → log skip, no attach.
5. payment already assigned (clientId set) + `learnSenders` → learn only, no attach.
6. attach returns false → warning logged, learning still runs (identity knowledge is independent of the attach capability).
7. `learnSenders === false` → attach happens but learner is never called.

- [ ] **Step 1: Write the failing tests** — create `tests/Statement/StatementReMatcherTest.php` with spy doubles for the four collaborators (`PaymentFinder`/`PaymentUpdater`/`ClientAccountLearner` are final classes — wrap them the way the suite handles final classes: they are NOT interfaces, so introduce interfaces? NO — keep it simple: `StatementReMatcher` must depend on the concrete final classes' public methods; for testability the plan defines three narrow interfaces instead:)

**Revision for testability (binding):** create these one-method interfaces in the same task and have the concrete classes implement them:
- `src/Ucrm/PaymentFinderInterface.php` — `findForStatementRow(string $transactionId, string $dateYmd, float $amount): ?array`
- `src/Ucrm/PaymentUpdaterInterface.php` — `attachClient(int $paymentId, int $clientId): bool`
- `src/Ucrm/AccountLearner.php` (interface) — `learn(int $clientId, array $accountNumbers): void`

`StatementReMatcher` type-hints the interfaces; tests use anonymous classes. Example test (write all seven from the matrix in this style):

```php
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
```

Helper builders (`finderReturning`, `updaterSpy`, `clientsByIban`, `learnerSpy`, `logger`) are private methods returning anonymous classes implementing the interfaces above; `clientsByIban` matches by `ClientMatcher::normalizeIban` of the argument.

- [ ] **Step 2: Run to verify failure** — classes/interfaces not found.

- [ ] **Step 3: Implement** — the three interfaces (one method each, same docblocks as the concrete classes), add `implements` to `PaymentFinder`, `PaymentUpdater`, `ClientAccountLearner`, then create `src/Statement/StatementReMatcher.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Statement;

use RevolutPaymentsImport\Matching\ClientRepository;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\AccountLearner;
use RevolutPaymentsImport\Ucrm\PaymentFinderInterface;
use RevolutPaymentsImport\Ucrm\PaymentUpdaterInterface;

/**
 * Second chance for statement rows whose transaction was already imported:
 * finds the existing UISP payment and, when it is unassigned, attaches the
 * client recognized by sender IBAN (then sender name). Optionally teaches the
 * client's sender identities so future webhook payments (which carry no IBAN)
 * match by name automatically.
 */
final class StatementReMatcher
{
    public function __construct(
        private readonly PaymentFinderInterface $payments,
        private readonly PaymentUpdaterInterface $updater,
        private readonly ClientRepository $clients,
        private readonly AccountLearner $learner,
        private readonly Logger $logger,
        private readonly bool $learnSenders,
    ) {
    }

    /** @param array{id:string,date:string,amount:float,currency:string,reference:string,senderName:string,senderIban:string} $row */
    public function reMatch(array $row): void
    {
        $payment = $this->payments->findForStatementRow($row['id'], $row['date'], $row['amount']);
        if ($payment === null) {
            return;
        }

        $client = null;
        if ($row['senderIban'] !== '') {
            $client = $this->clients->findClientByIban($row['senderIban']);
        }
        if ($client === null && $row['senderName'] !== '') {
            $client = $this->clients->findClientByIban($row['senderName']);
        }
        $clientId = isset($client['id']) ? (int) $client['id'] : null;

        $paymentClientId = isset($payment['clientId']) && $payment['clientId'] !== null ? (int) $payment['clientId'] : null;
        $identities = array_values(array_filter([$row['senderIban'], $row['senderName']], static fn (string $v): bool => $v !== ''));

        if ($paymentClientId !== null) {
            // Already attached (manually or by matching) — just learn identities.
            if ($this->learnSenders && $identities !== []) {
                $this->learner->learn($paymentClientId, $identities);
            }

            return;
        }

        if ($clientId === null) {
            $this->logger->info(sprintf('Re-match: %s — no client recognized for "%s"; leaving unassigned.', $row['id'], $row['senderName']));

            return;
        }

        $paymentId = (int) ($payment['id'] ?? 0);
        if (! $this->updater->attachClient($paymentId, $clientId)) {
            $this->logger->error(sprintf(
                'Re-match: could not attach payment %d to client %d (UISP may not support PATCHing payments) — attach it manually.',
                $paymentId,
                $clientId,
            ));
        } else {
            $this->logger->info(sprintf('Re-match: payment %d attached to client %d (%s).', $paymentId, $clientId, $row['senderName']));
        }

        if ($this->learnSenders && $identities !== []) {
            $this->learner->learn($clientId, $identities);
        }
    }
}
```

- [ ] **Step 4: Run the full suite** — expected PASS.

- [ ] **Step 5: Commit**

```powershell
git add plugins/revolut-payments-import/src/src plugins/revolut-payments-import/src/tests
git commit -m "feat(revolut): statement re-match attaches and learns sender identities"
```

---

### Task 5: Wire re-matcher + name fallback into StatementImporter

**Files:**
- Modify: `src/Statement/StatementImporter.php`
- Test: `tests/Statement/StatementImporterTest.php`

**Interfaces:**
- Produces: constructor gains an optional trailing param `?StatementReMatcher $reMatcher = null`; behavior changes: (a) processed rows are delegated to `$reMatcher->reMatch($row)` instead of skipped; (b) new-row matching falls back to sender name.

- [ ] **Step 1: Write the failing tests** — add to `tests/Statement/StatementImporterTest.php` (following its existing double style):
  - `testProcessedRowIsDelegatedToReMatcher` — idempotency store already contains the row id; a spy `StatementReMatcher`… `StatementReMatcher` is final: give it an interface too? NO — keep the plan lean: make `StatementImporter` depend on a new one-method interface `Statement/ReMatcher.php` (`reMatch(array $row): void`), implemented by `StatementReMatcher`. The test uses an anonymous spy implementing `ReMatcher` and asserts it received the row and that no payment was created.
  - `testNewRowFallsBackToSenderNameWhenIbanUnknown` — repository double returns null for the IBAN and client 42 for the sender name; assert the recorded payment has clientId 42.

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement** — create `src/Statement/ReMatcher.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Statement;

interface ReMatcher
{
    /** @param array{id:string,date:string,amount:float,currency:string,reference:string,senderName:string,senderIban:string} $row */
    public function reMatch(array $row): void;
}
```

Add `implements ReMatcher` to `StatementReMatcher`. In `StatementImporter`:
- constructor: add trailing `private readonly ?ReMatcher $reMatcher = null,`
- replace the skip:

```php
            if ($this->idempotency->isProcessed($row['id'])) {
                continue;
            }
```

with:

```php
            if ($this->idempotency->isProcessed($row['id'])) {
                // Already imported (or deliberately skipped) — give the row a
                // second chance: attach/learn on the existing payment.
                $this->reMatcher?->reMatch($row);

                continue;
            }
```

- name fallback for new rows — replace:

```php
            $client = $row['senderIban'] !== '' ? $this->clients->findClientByIban($row['senderIban']) : null;
```

with:

```php
            $client = $row['senderIban'] !== '' ? $this->clients->findClientByIban($row['senderIban']) : null;
            if ($client === null && $row['senderName'] !== '') {
                $client = $this->clients->findClientByIban($row['senderName']);
            }
```

- [ ] **Step 4: Run the full suite** — expected PASS.

- [ ] **Step 5: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Statement plugins/revolut-payments-import/src/tests/Statement
git commit -m "feat(revolut): statement importer re-matches processed rows and falls back to sender name"
```

---

### Task 6: Config, manifest, main.php wiring, diagnostics removal

**Files:**
- Modify: `src/Config/PluginConfig.php`, `manifest.json`, `main.php`, `public.php`
- Test: `tests/Config/PluginConfigTest.php`

- [ ] **Step 1: Failing config test** — add to `tests/Config/PluginConfigTest.php` (existing style writes temp JSON files):

```php
    public function testLearnSendersDefaultsOnAndHonorsExplicitOff(): void
    {
        self::assertTrue($this->config([])->learnSenders());
        self::assertTrue($this->config(['learnSenders' => true])->learnSenders());
        self::assertTrue($this->config(['learnSenders' => '1'])->learnSenders());
        self::assertFalse($this->config(['learnSenders' => false])->learnSenders());
        self::assertFalse($this->config(['learnSenders' => '0'])->learnSenders());
    }
```

(If the test file has no `config(array $values)` helper, add one that writes the array as JSON to a temp file and calls `PluginConfig::fromFile`.)

- [ ] **Step 2: Implement `learnSenders()`** in `src/Config/PluginConfig.php`:

```php
    /** Sender-identity learning from statement imports; enabled unless explicitly off. */
    public function learnSenders(): bool
    {
        $value = $this->values['learnSenders'] ?? null;

        return ! in_array($value, [false, 0, '0', 'false'], true);
    }
```

- [ ] **Step 3: Manifest** — in `manifest.json` bump version to `1.8.0` and add to `configuration` (after the `statementCsv` entry):

```json
        {
            "key": "learnSenders",
            "label": "Learn sender identities from statement import",
            "description": "When the statement CSV recognizes a client by sender IBAN, store that IBAN and the sender name on the client's bank accounts so future webhook payments (which carry no IBAN) are matched by sender name automatically. Enabled unless unchecked.",
            "required": 0,
            "type": "checkbox"
        },
```

- [ ] **Step 4: Wire main.php** — in the statement import block of `main.php`, replace the `StatementImporter` construction with:

```php
                $ucrmPayments = new PaymentFinder($ucrm);
                $reMatcher = new StatementReMatcher(
                    $ucrmPayments,
                    new PaymentUpdater($ucrm),
                    new ClientMatcher($ucrm),
                    new ClientAccountLearner($ucrm, $logger),
                    $logger,
                    $config->learnSenders(),
                );
                $importer = new StatementImporter(
                    new ClientMatcher($ucrm),
                    new UcrmPaymentGateway($ucrm, (string) $config->paymentMethodName()),
                    new UcrmPaymentLookup($ucrm),
                    new IdempotencyStore(__DIR__ . '/data/processed.json'),
                    $logger,
                    $reMatcher,
                );
```

Add the missing `use` statements at the top of `main.php`: `RevolutPaymentsImport\Statement\StatementReMatcher`, `RevolutPaymentsImport\Ucrm\ClientAccountLearner`, `RevolutPaymentsImport\Ucrm\PaymentFinder`, `RevolutPaymentsImport\Ucrm\PaymentUpdater`.

**Statement re-run note (binding):** re-uploading the same file does nothing (`statementDone` hash unchanged) — that is existing behavior and stays. The README (Task 8) must tell the user: to re-process a statement, upload the file again (any content change) or upload a fresh export.

- [ ] **Step 5: Remove the temporary webhook diagnostic** — in `public.php` delete the v1.7.1 block:

```php
// Diagnostic (v1.7.1): keep the authentic raw payload in the plugin log so
// what Revolut *sends* can be compared against what GET /transaction returns
// (sender-IBAN investigation). Remove once the question is settled.
$logger->info('Webhook raw payload: ' . $rawBody);
```

- [ ] **Step 6: Lint + full suite**

Run: `docker run --rm -v "...\src:/app" -w /app ucrm-php-test:8.3 php -l main.php` → clean; same for `public.php`; then the full suite → PASS.

- [ ] **Step 7: Commit**

```powershell
git add plugins/revolut-payments-import/src
git commit -m "feat(revolut): wire sender-identity learning, config toggle, bump to 1.8.0"
```

---

### Task 7: Status page — copyable sender key

**Files:**
- Modify: `public.php` (`renderStatusBody`)

- [ ] **Step 1: Implement** — in `renderStatusBody()`, for rows with status `STATUS_UNASSIGNED` or `STATUS_MISSING` make the sender cell show the name plus a click-to-copy affordance. Replace:

```php
            . '<td>' . htmlspecialchars($row->sender) . '</td>'
```

with:

```php
            . '<td>' . htmlspecialchars($row->sender)
            . ($row->clientId === null && $row->sender !== ''
                ? ' <a href="#" class="copy-sender" data-sender="' . htmlspecialchars($row->sender)
                    . '" title="Копирай името — добавете го като Bank account на клиента в UISP и всички бъдещи преводи от този изпращач ще се разнасят автоматично">⧉</a>'
                : '')
            . '</td>'
```

and append once, right before `renderStatusBody`'s final `return $html;`:

```php
    $html .= '<script>document.addEventListener("click",function(e){'
        . 'var a=e.target.closest("a.copy-sender");if(!a)return;e.preventDefault();'
        . 'navigator.clipboard.writeText(a.dataset.sender).then(function(){a.textContent="✓";setTimeout(function(){a.textContent="⧉";},1500);});'
        . '});</script>';
```

- [ ] **Step 2: Lint** — `php -l public.php` → clean. Full suite → PASS (no logic classes touched).

- [ ] **Step 3: Commit**

```powershell
git add plugins/revolut-payments-import/src/public.php
git commit -m "feat(revolut): copyable sender key on unassigned status rows"
```

---

### Task 8: README + full verification

**Files:**
- Modify: `plugins/revolut-payments-import/README.md`

- [ ] **Step 1: Add section** after "Monthly status page":

```markdown
## Sender-identity matching (no-IBAN transfers)
Revolut's API exposes no sender IBAN for external incoming transfers, so the
plugin also matches by the **sender's name**, fed through the same
bank-account matching as IBANs: add the sender name (as shown on the status
page — use the ⧉ copy icon) as an extra *Bank account* entry on the client in
UISP, and every future transfer from that sender is attached automatically.
A client can hold any number of such entries (IBANs and names mixed);
matching ignores case and whitespace.

**Statement upload attaches and learns.** When you upload an account
statement CSV, rows whose transaction was already imported get a second pass:
if the payment is still unassigned, the plugin recognizes the client by the
statement's `Sender account` IBAN (or sender name) and attaches the payment.
With **Learn sender identities** enabled (default), it also stores the sender
IBAN + name on the recognized client — one statement upload teaches the
plugin to auto-match all your senders from then on. Re-processing happens
only when the uploaded file's content changes, so upload a fresh export to
re-run. If your UISP version rejects attaching payments via API, the log
says so per payment and everything else still works.
```

- [ ] **Step 2: Full suite + lint of both entry points** — PASS/clean.

- [ ] **Step 3: Commit**

```powershell
git add plugins/revolut-payments-import/README.md
git commit -m "docs(revolut): document sender-identity matching and statement learning"
```

---

### Task 9: Rebuild the plugin zip

- [ ] **Step 1: Pack:** `docker run --rm -v "C:\Users\rosen\UCRM-plugins:/repo" -w /repo ucrm-php-test:8.3 php pack-plugin.php revolut-payments-import` → exit 0.
- [ ] **Step 2: Restore dev deps:** `composer install` in the src mount; re-run full suite → PASS.
- [ ] **Step 3:** `git status --short` → only the zip modified.
- [ ] **Step 4: Commit:**

```powershell
git add plugins/revolut-payments-import/revolut-payments-import.zip
git commit -m "chore(revolut): rebuild plugin zip for v1.8.0"
```
