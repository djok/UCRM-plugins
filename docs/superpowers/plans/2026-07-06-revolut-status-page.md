# Revolut Monthly Status Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin-only page in `revolut-payments-import` showing every incoming Revolut transfer of a selected month with its reconciliation status against UISP/UCRM payments (Bulgarian UI).

**Architecture:** Live page at `public.php?status=1&month=YYYY-MM` following the existing `?accounts=1` pattern. New pure-logic classes under `src/Status/` (`MonthWindow`, `StatusRow`, `MonthlyStatusReport`) do all computation and are unit-tested; `public.php` only authenticates, fetches (Revolut transactions + UCRM payments), and renders. `UcrmPaymentGateway` starts stamping `providerName`/`providerPaymentId` for exact matching going forward; legacy payments match heuristically (note prefix + amount + date).

**Tech Stack:** PHP 8.1 (strict types, final classes, readonly properties), PHPUnit 10.5, UCRM Plugin SDK. No new composer dependencies.

**Spec:** `docs/superpowers/specs/2026-07-06-revolut-status-page-design.md`

## Global Constraints

- Plugin root: `plugins/revolut-payments-import/src` — all PHP paths below are relative to it unless prefixed with `docs/` or repo root.
- **No local PHP.** Every PHP command runs in Docker via the **PowerShell tool** (Git Bash mangles `-w /app`):
  `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 <command>`
- Code style: `declare(strict_types=1);`, PSR-12, `final` classes, `readonly` properties, English comments. UI strings on the status page are **Bulgarian**.
- Provider name constant is exactly `'Revolut'`; heuristic note prefix is exactly `'Revolut: '`; amount tolerance `0.005`.
- Incoming-transfer rules mirror `Webhook/EventProcessor.php`: `state === 'completed'`, `type` in `{transfer, topup}`, first leg with amount > 0, lowercase account-id filter (empty = all).
- Commits: `<type>(revolut): <description>` — no attribution footers (disabled globally in ~/.claude/settings.json).
- New version: **1.6.0**.

---

### Task 1: Stamp providerName/providerPaymentId on recorded payments

**Files:**
- Modify: `src/Ucrm/UcrmPaymentGateway.php`
- Test: `tests/Ucrm/UcrmPaymentGatewayTest.php`

**Interfaces:**
- Consumes: existing `IncomingPayment->externalId` (Revolut transaction id).
- Produces: `UcrmPaymentGateway::PROVIDER_NAME = 'Revolut'` (public const, used by Task 3 for exact matching); every POSTed payment body now contains `providerName` and `providerPaymentId`.

- [ ] **Step 1: Write the failing test** — append to `tests/Ucrm/UcrmPaymentGatewayTest.php` (inside the class):

```php
    public function testStampsProviderNameAndPaymentId(): void
    {
        $ucrm = $this->ucrm();
        $gateway = new UcrmPaymentGateway($ucrm, 'Bank transfer');

        $gateway->record(new IncomingPayment(12.44, 'EUR', 42, 'Revolut: John', 'tx-abc'));

        $data = $ucrm->posted[0]['data'];
        self::assertSame('Revolut', $data['providerName']);
        self::assertSame('tx-abc', $data['providerPaymentId']);
        self::assertSame('Revolut', UcrmPaymentGateway::PROVIDER_NAME);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run (PowerShell): `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit --filter UcrmPaymentGatewayTest`
Expected: FAIL — `Undefined constant ... PROVIDER_NAME` (or missing array key `providerName`).

- [ ] **Step 3: Implement** — in `src/Ucrm/UcrmPaymentGateway.php` add the constant right after the class opening brace, and extend the POST body in `record()`:

```php
final class UcrmPaymentGateway implements PaymentRecorder
{
    /** Stamped on every created payment; the status page matches on it. */
    public const PROVIDER_NAME = 'Revolut';

    private ?string $methodId = null;
```

```php
        $data = [
            'amount' => $payment->amount,
            'currencyCode' => $payment->currencyCode,
            'methodId' => $this->resolveMethodId(),
            'note' => $payment->note,
            'providerName' => self::PROVIDER_NAME,
            'providerPaymentId' => $payment->externalId,
        ];
```

- [ ] **Step 4: Run the full suite to verify green**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit`
Expected: PASS (all tests).

- [ ] **Step 5: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Ucrm/UcrmPaymentGateway.php plugins/revolut-payments-import/src/tests/Ucrm/UcrmPaymentGatewayTest.php
git commit -m "feat(revolut): stamp providerName/providerPaymentId on created payments"
```

---

### Task 2: StatusRow DTO + MonthlyStatusReport transfer filtering

**Files:**
- Create: `src/Status/StatusRow.php`
- Create: `src/Status/MonthlyStatusReport.php`
- Test: `tests/Status/MonthlyStatusReportTest.php`

**Interfaces:**
- Consumes: raw Revolut transaction arrays (same shape `EventProcessor` handles), `UcrmPaymentGateway::PROVIDER_NAME`.
- Produces:
  - `StatusRow` readonly DTO with consts `STATUS_ASSIGNED|STATUS_UNASSIGNED|STATUS_SKIPPED|STATUS_MISSING` and props `transactionId, date, amount, currency, sender, reference, status, clientId`.
  - `MonthlyStatusReport::__construct(array $allowedAccountIds = [])`
  - `MonthlyStatusReport::build(array $transactions, array $payments, callable $isProcessed): array` returning `list<StatusRow>` (payment matching lands in Task 3; this task wires only skipped/missing).

- [ ] **Step 1: Write the DTO** — create `src/Status/StatusRow.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Status;

/**
 * One incoming Revolut transfer of the selected month with its UISP
 * reconciliation status. Immutable; rendered as a table row.
 */
final class StatusRow
{
    /** Payment exists in UISP and is attached to a client. */
    public const STATUS_ASSIGNED = 'assigned';
    /** Payment exists in UISP but has no client (waiting for manual attachment). */
    public const STATUS_UNASSIGNED = 'unassigned';
    /** No imported payment, but the id is in processed.json — the duplicate
     * guard recognized a manually entered payment. */
    public const STATUS_SKIPPED = 'skipped';
    /** The plugin never handled this transaction. */
    public const STATUS_MISSING = 'missing';

    public function __construct(
        public readonly string $transactionId,
        public readonly string $date,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $sender,
        public readonly string $reference,
        public readonly string $status,
        public readonly ?int $clientId = null,
    ) {
    }
}
```

- [ ] **Step 2: Write the failing tests** — create `tests/Status/MonthlyStatusReportTest.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Status;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Status\MonthlyStatusReport;
use RevolutPaymentsImport\Status\StatusRow;

final class MonthlyStatusReportTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function tx(string $id, float $amount = 50.0, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'state' => 'completed',
            'type' => 'transfer',
            'completed_at' => '2026-06-15T10:00:00Z',
            'reference' => 'Invoice 1',
            'legs' => [[
                'account_id' => 'acc-1',
                'amount' => $amount,
                'currency' => 'EUR',
                'description' => 'Payment from ACME LTD',
            ]],
        ], $overrides);
    }

    private static function notProcessed(): callable
    {
        return static fn (string $id): bool => false;
    }

    public function testCompletedIncomingTransferBecomesRow(): void
    {
        $rows = (new MonthlyStatusReport())->build([$this->tx('tx-1', 42.5)], [], self::notProcessed());

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('tx-1', $row->transactionId);
        self::assertSame('2026-06-15', $row->date);
        self::assertSame(42.5, $row->amount);
        self::assertSame('EUR', $row->currency);
        self::assertSame('ACME LTD', $row->sender);
        self::assertSame('Invoice 1', $row->reference);
        self::assertSame(StatusRow::STATUS_MISSING, $row->status);
        self::assertNull($row->clientId);
    }

    public function testNonCompletedAndNonIncomingTypesAreExcluded(): void
    {
        $transactions = [
            $this->tx('pending', 10.0, ['state' => 'pending']),
            $this->tx('card', 10.0, ['type' => 'card_payment']),
            $this->tx('exchange', 10.0, ['type' => 'exchange']),
            $this->tx('topup', 10.0, ['type' => 'topup']),
        ];

        $rows = (new MonthlyStatusReport())->build($transactions, [], self::notProcessed());

        self::assertSame(['topup'], array_map(static fn (StatusRow $r): string => $r->transactionId, $rows));
    }

    public function testOutgoingTransfersAreExcluded(): void
    {
        $out = $this->tx('out', -20.0, ['legs' => [['account_id' => 'acc-1', 'amount' => -20.0, 'currency' => 'EUR']]]);

        self::assertSame([], (new MonthlyStatusReport())->build([$out], [], self::notProcessed()));
    }

    public function testAccountFilterIsCaseInsensitive(): void
    {
        $report = new MonthlyStatusReport(['ACC-1']);
        $transactions = [
            $this->tx('kept'),
            $this->tx('dropped', 50.0, ['legs' => [['account_id' => 'acc-2', 'amount' => 50.0, 'currency' => 'EUR']]]),
        ];

        $rows = $report->build($transactions, [], self::notProcessed());

        self::assertSame(['kept'], array_map(static fn (StatusRow $r): string => $r->transactionId, $rows));
    }

    public function testProcessedTransferWithoutPaymentIsSkippedStatus(): void
    {
        $isProcessed = static fn (string $id): bool => $id === 'tx-manual';

        $rows = (new MonthlyStatusReport())->build([$this->tx('tx-manual')], [], $isProcessed);

        self::assertSame(StatusRow::STATUS_SKIPPED, $rows[0]->status);
    }

    public function testDateFallsBackToCreatedAtAndBulgarianDescriptionPrefixIsStripped(): void
    {
        $tx = $this->tx('tx-bg', 5.0, [
            'completed_at' => null,
            'created_at' => '2026-06-02T08:00:00Z',
            'reference' => '',
            'legs' => [[
                'account_id' => 'acc-1',
                'amount' => 5.0,
                'currency' => 'EUR',
                'description' => 'Добавени пари от ИВАН ИВАНОВ',
            ]],
        ]);

        $row = (new MonthlyStatusReport())->build([$tx], [], self::notProcessed())[0];

        self::assertSame('2026-06-02', $row->date);
        self::assertSame('ИВАН ИВАНОВ', $row->sender);
        self::assertSame('', $row->reference);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit --filter MonthlyStatusReportTest`
Expected: ERROR — `Class "RevolutPaymentsImport\Status\MonthlyStatusReport" not found`.

- [ ] **Step 4: Implement** — create `src/Status/MonthlyStatusReport.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Status;

/**
 * Pure reconciliation logic for the status page: filters raw Revolut
 * transactions down to in-scope incoming transfers (same rules as
 * Webhook\EventProcessor — keep in sync) and resolves each transfer's
 * UISP payment status. No I/O; callers fetch and render.
 */
final class MonthlyStatusReport
{
    /** Keep in sync with EventProcessor::INCOMING_TYPES. */
    private const INCOMING_TYPES = ['transfer', 'topup'];

    /** @var list<string> lowercase account ids; empty = all accounts */
    private readonly array $allowedAccountIds;

    /** @param list<string> $allowedAccountIds */
    public function __construct(array $allowedAccountIds = [])
    {
        $this->allowedAccountIds = array_values(array_map(
            static fn ($id): string => strtolower(trim((string) $id)),
            $allowedAccountIds,
        ));
    }

    /**
     * @param list<array<mixed>> $transactions raw Revolut transactions
     * @param list<array<mixed>> $payments raw UISP payments of the same month
     * @param callable(string):bool $isProcessed idempotency-store lookup
     * @return list<StatusRow>
     */
    public function build(array $transactions, array $payments, callable $isProcessed): array
    {
        $rows = [];
        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }
            $id = $transaction['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            if (($transaction['state'] ?? null) !== 'completed') {
                continue;
            }
            if (! in_array($transaction['type'] ?? null, self::INCOMING_TYPES, true)) {
                continue;
            }
            $leg = $this->incomingLeg($transaction);
            if ($leg === null) {
                continue;
            }
            if ($this->allowedAccountIds !== []) {
                $accountId = strtolower((string) ($leg['account_id'] ?? ''));
                if (! in_array($accountId, $this->allowedAccountIds, true)) {
                    continue;
                }
            }

            $completedAt = $transaction['completed_at'] ?? $transaction['created_at'] ?? '';
            $status = $isProcessed($id) ? StatusRow::STATUS_SKIPPED : StatusRow::STATUS_MISSING;

            $rows[] = new StatusRow(
                transactionId: $id,
                date: substr(is_string($completedAt) ? $completedAt : '', 0, 10),
                amount: (float) $leg['amount'],
                currency: (string) ($leg['currency'] ?? ''),
                sender: $this->senderFromLeg($leg),
                reference: (string) ($transaction['reference'] ?? ''),
                status: $status,
            );
        }

        return $rows;
    }

    /**
     * @param array<mixed> $transaction
     * @return array<mixed>|null first leg with a positive amount
     */
    private function incomingLeg(array $transaction): ?array
    {
        $legs = $transaction['legs'] ?? [];
        if (! is_array($legs)) {
            return null;
        }
        foreach ($legs as $leg) {
            if (is_array($leg) && isset($leg['amount']) && (float) $leg['amount'] > 0.0) {
                return $leg;
            }
        }

        return null;
    }

    /** @param array<mixed> $leg */
    private function senderFromLeg(array $leg): string
    {
        $description = $leg['description'] ?? '';
        if (! is_string($description)) {
            return '';
        }

        return (string) preg_replace('/^(payment from|добавени пари от)\s+/iu', '', $description);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit --filter MonthlyStatusReportTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Status plugins/revolut-payments-import/src/tests/Status
git commit -m "feat(revolut): status report transfer filtering with skipped/missing states"
```

---

### Task 3: Payment matching (exact + legacy heuristic, with consumption)

**Files:**
- Modify: `src/Status/MonthlyStatusReport.php`
- Test: `tests/Status/MonthlyStatusReportTest.php`

**Interfaces:**
- Consumes: `UcrmPaymentGateway::PROVIDER_NAME` (Task 1); UISP payment arrays with keys `providerName`, `providerPaymentId`, `note`, `amount`, `createdDate`, `clientId`.
- Produces: `build()` now resolves `STATUS_ASSIGNED` / `STATUS_UNASSIGNED` and fills `StatusRow->clientId`. Matching order: exact provider id, then heuristic (note prefix `Revolut: `, amount ±0.005, same `Y-m-d`); each payment matches at most one transfer.

- [ ] **Step 1: Write the failing tests** — append to `tests/Status/MonthlyStatusReportTest.php` (inside the class):

```php
    /** @param array<string,mixed> $overrides */
    private function payment(array $overrides = []): array
    {
        return array_replace([
            'id' => 1,
            'clientId' => 42,
            'amount' => 50.0,
            'currencyCode' => 'EUR',
            'note' => 'Revolut: ACME LTD | Invoice 1',
            'createdDate' => '2026-06-15T10:00:00+00:00',
            'providerName' => null,
            'providerPaymentId' => null,
        ], $overrides);
    }

    public function testExactProviderIdMatchIsAssigned(): void
    {
        $payment = $this->payment(['providerName' => 'Revolut', 'providerPaymentId' => 'tx-1', 'amount' => 999.0]);

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-1')], [$payment], self::notProcessed())[0];

        self::assertSame(StatusRow::STATUS_ASSIGNED, $row->status);
        self::assertSame(42, $row->clientId);
    }

    public function testExactMatchWithoutClientIsUnassigned(): void
    {
        $payment = $this->payment(['providerName' => 'Revolut', 'providerPaymentId' => 'tx-1', 'clientId' => null]);

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-1')], [$payment], self::notProcessed())[0];

        self::assertSame(StatusRow::STATUS_UNASSIGNED, $row->status);
        self::assertNull($row->clientId);
    }

    public function testForeignProviderIdIsNotExactMatched(): void
    {
        $payment = $this->payment(['providerName' => 'Fio CZ', 'providerPaymentId' => 'tx-1', 'note' => 'other', 'amount' => 999.0]);

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-1')], [$payment], self::notProcessed())[0];

        self::assertSame(StatusRow::STATUS_MISSING, $row->status);
    }

    public function testLegacyPaymentMatchesByNoteAmountAndDate(): void
    {
        $row = (new MonthlyStatusReport())->build([$this->tx('tx-1')], [$this->payment()], self::notProcessed())[0];

        self::assertSame(StatusRow::STATUS_ASSIGNED, $row->status);
        self::assertSame(42, $row->clientId);
    }

    public function testLegacyMatchRejectsDifferentAmountOrDate(): void
    {
        $report = new MonthlyStatusReport();
        $wrongAmount = $this->payment(['amount' => 50.01]);
        $wrongDate = $this->payment(['createdDate' => '2026-06-16T10:00:00+00:00']);

        self::assertSame(StatusRow::STATUS_MISSING, $report->build([$this->tx('tx-1')], [$wrongAmount], self::notProcessed())[0]->status);
        self::assertSame(StatusRow::STATUS_MISSING, $report->build([$this->tx('tx-1')], [$wrongDate], self::notProcessed())[0]->status);
    }

    public function testManualPaymentWithoutRevolutNoteIsNeverHeuristicallyMatched(): void
    {
        $manual = $this->payment(['note' => 'платено на каса']);

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-1')], [$manual], self::notProcessed())[0];

        self::assertSame(StatusRow::STATUS_MISSING, $row->status);
    }

    public function testLegacyPaymentIsConsumedByFirstTransferOnly(): void
    {
        $transfers = [$this->tx('tx-1'), $this->tx('tx-2')];

        $rows = (new MonthlyStatusReport())->build($transfers, [$this->payment()], self::notProcessed());

        self::assertSame(
            [StatusRow::STATUS_ASSIGNED, StatusRow::STATUS_MISSING],
            array_map(static fn (StatusRow $r): string => $r->status, $rows),
        );
    }
```

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit --filter MonthlyStatusReportTest`
Expected: FAIL — exact/legacy match tests report `missing` instead of `assigned`.

- [ ] **Step 3: Implement matching** — in `src/Status/MonthlyStatusReport.php`:

Add the epsilon const under `INCOMING_TYPES` and import the gateway const:

```php
use RevolutPaymentsImport\Ucrm\UcrmPaymentGateway;
```

```php
    /** Same tolerance as UcrmPaymentLookup::AMOUNT_EPSILON. */
    private const AMOUNT_EPSILON = 0.005;
    private const LEGACY_NOTE_PREFIX = 'Revolut: ';
```

In `build()`, index the payments right before the transactions loop:

```php
        $byProviderId = [];
        $legacyPool = [];
        foreach ($payments as $index => $payment) {
            if (! is_array($payment)) {
                continue;
            }
            $providerId = (string) ($payment['providerPaymentId'] ?? '');
            if (($payment['providerName'] ?? null) === UcrmPaymentGateway::PROVIDER_NAME && $providerId !== '') {
                $byProviderId[$providerId] = $payment;

                continue;
            }
            if (str_starts_with((string) ($payment['note'] ?? ''), self::LEGACY_NOTE_PREFIX)) {
                $legacyPool[$index] = $payment;
            }
        }
```

Replace the block from the `$completedAt = ...` line through the `$rows[] = new StatusRow(...);` statement (end of the loop body) with:

```php
            $completedAt = $transaction['completed_at'] ?? $transaction['created_at'] ?? '';
            $date = substr(is_string($completedAt) ? $completedAt : '', 0, 10);
            $amount = (float) $leg['amount'];

            $payment = $byProviderId[$id] ?? null;
            if ($payment === null) {
                foreach ($legacyPool as $index => $candidate) {
                    if (
                        abs((float) ($candidate['amount'] ?? 0.0) - $amount) < self::AMOUNT_EPSILON
                        && substr((string) ($candidate['createdDate'] ?? ''), 0, 10) === $date
                    ) {
                        $payment = $candidate;
                        unset($legacyPool[$index]); // a payment backs at most one transfer

                        break;
                    }
                }
            }

            $clientId = $payment !== null && isset($payment['clientId']) && $payment['clientId'] !== null
                ? (int) $payment['clientId']
                : null;
            $status = match (true) {
                $payment !== null && $clientId !== null => StatusRow::STATUS_ASSIGNED,
                $payment !== null => StatusRow::STATUS_UNASSIGNED,
                $isProcessed($id) => StatusRow::STATUS_SKIPPED,
                default => StatusRow::STATUS_MISSING,
            };

            $rows[] = new StatusRow(
                transactionId: $id,
                date: $date,
                amount: $amount,
                currency: (string) ($leg['currency'] ?? ''),
                sender: $this->senderFromLeg($leg),
                reference: (string) ($transaction['reference'] ?? ''),
                status: $status,
                clientId: $clientId,
            );
```

- [ ] **Step 4: Run the full suite**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Status/MonthlyStatusReport.php plugins/revolut-payments-import/src/tests/Status/MonthlyStatusReportTest.php
git commit -m "feat(revolut): match transfers to UISP payments (provider id + legacy heuristic)"
```

---

### Task 4: Per-status summary totals

**Files:**
- Modify: `src/Status/MonthlyStatusReport.php`
- Test: `tests/Status/MonthlyStatusReportTest.php`

**Interfaces:**
- Produces: `MonthlyStatusReport::summarize(array $rows): array` returning `array<string, array{count:int, amounts:array<string,float>}>` keyed by every `StatusRow::STATUS_*` (all four keys always present).

- [ ] **Step 1: Write the failing test** — append to `tests/Status/MonthlyStatusReportTest.php`:

```php
    public function testSummarizeCountsAndSumsPerCurrency(): void
    {
        $rows = [
            new StatusRow('a', '2026-06-01', 10.0, 'EUR', '', '', StatusRow::STATUS_ASSIGNED, 1),
            new StatusRow('b', '2026-06-02', 5.5, 'EUR', '', '', StatusRow::STATUS_ASSIGNED, 2),
            new StatusRow('c', '2026-06-03', 7.0, 'USD', '', '', StatusRow::STATUS_ASSIGNED, 3),
            new StatusRow('d', '2026-06-04', 99.0, 'EUR', '', '', StatusRow::STATUS_MISSING),
        ];

        $summary = (new MonthlyStatusReport())->summarize($rows);

        self::assertSame(3, $summary[StatusRow::STATUS_ASSIGNED]['count']);
        self::assertSame(15.5, $summary[StatusRow::STATUS_ASSIGNED]['amounts']['EUR']);
        self::assertSame(7.0, $summary[StatusRow::STATUS_ASSIGNED]['amounts']['USD']);
        self::assertSame(1, $summary[StatusRow::STATUS_MISSING]['count']);
        self::assertSame(0, $summary[StatusRow::STATUS_UNASSIGNED]['count']);
        self::assertSame([], $summary[StatusRow::STATUS_SKIPPED]['amounts']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit --filter testSummarizeCountsAndSumsPerCurrency`
Expected: ERROR — `Call to undefined method ... summarize()`.

- [ ] **Step 3: Implement** — add to `MonthlyStatusReport`:

```php
    /**
     * @param list<StatusRow> $rows
     * @return array<string, array{count:int, amounts:array<string,float>}>
     */
    public function summarize(array $rows): array
    {
        $statuses = [
            StatusRow::STATUS_ASSIGNED,
            StatusRow::STATUS_UNASSIGNED,
            StatusRow::STATUS_SKIPPED,
            StatusRow::STATUS_MISSING,
        ];
        $summary = [];
        foreach ($statuses as $status) {
            $summary[$status] = ['count' => 0, 'amounts' => []];
        }
        foreach ($rows as $row) {
            $summary[$row->status]['count']++;
            $summary[$row->status]['amounts'][$row->currency] =
                ($summary[$row->status]['amounts'][$row->currency] ?? 0.0) + $row->amount;
        }

        return $summary;
    }
```

- [ ] **Step 4: Run the full suite**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Status/MonthlyStatusReport.php plugins/revolut-payments-import/src/tests/Status/MonthlyStatusReportTest.php
git commit -m "feat(revolut): per-status per-currency summary for the status report"
```

---

### Task 5: MonthWindow (month selection + UTC window math)

**Files:**
- Create: `src/Status/MonthWindow.php`
- Test: `tests/Status/MonthWindowTest.php`

**Interfaces:**
- Produces:
  - `MonthWindow::fromQuery(?string $month, int $nowTs): self` — readonly props `ym` ('2026-06'), `fromIso` ('2026-06-01T00:00:00Z'), `toIso` (min(now, first day of next month), same format), `fromDate` ('2026-06-01'), `toDate` (last day, '2026-06-30'). Invalid/absent/future `month` → current month.
  - `MonthWindow::lastMonths(int $count, int $nowTs): array` — `list<string>` of 'YYYY-MM', newest first, including the current month.

- [ ] **Step 1: Write the failing tests** — create `tests/Status/MonthWindowTest.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Status;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Status\MonthWindow;

final class MonthWindowTest extends TestCase
{
    private const NOW = 1783333800; // 2026-07-06T10:30:00Z

    public function testPastMonthProducesFullWindow(): void
    {
        $window = MonthWindow::fromQuery('2026-06', self::NOW);

        self::assertSame('2026-06', $window->ym);
        self::assertSame('2026-06-01T00:00:00Z', $window->fromIso);
        self::assertSame('2026-07-01T00:00:00Z', $window->toIso);
        self::assertSame('2026-06-01', $window->fromDate);
        self::assertSame('2026-06-30', $window->toDate);
    }

    public function testCurrentMonthClampsUpperBoundToNow(): void
    {
        $window = MonthWindow::fromQuery('2026-07', self::NOW);

        self::assertSame('2026-07-06T10:30:00Z', $window->toIso);
        self::assertSame('2026-07-31', $window->toDate);
    }

    public function testInvalidAbsentAndFutureMonthsFallBackToCurrent(): void
    {
        foreach ([null, '', 'junk', '2026-13', '2026-1', '2027-01'] as $input) {
            self::assertSame('2026-07', MonthWindow::fromQuery($input, self::NOW)->ym, var_export($input, true));
        }
    }

    public function testLeapFebruary(): void
    {
        self::assertSame('2028-02-29', MonthWindow::fromQuery('2028-02', strtotime('2028-06-01T00:00:00Z'))->toDate);
    }

    public function testLastMonthsCrossesYearBoundaryNewestFirst(): void
    {
        $months = MonthWindow::lastMonths(3, strtotime('2026-01-15T00:00:00Z'));

        self::assertSame(['2026-01', '2025-12', '2025-11'], $months);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit --filter MonthWindowTest`
Expected: ERROR — class not found.

- [ ] **Step 3: Implement** — create `src/Status/MonthWindow.php`:

```php
<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Status;

/**
 * UTC time window of one calendar month for the status page. The upper bound
 * is clamped to "now" so the current month queries only elapsed time.
 */
final class MonthWindow
{
    private function __construct(
        public readonly string $ym,
        public readonly string $fromIso,
        public readonly string $toIso,
        public readonly string $fromDate,
        public readonly string $toDate,
    ) {
    }

    public static function fromQuery(?string $month, int $nowTs): self
    {
        $currentYm = gmdate('Y-m', $nowTs);
        $ym = is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? $month
            : $currentYm;
        if ($ym > $currentYm) {
            $ym = $currentYm;
        }

        $start = new \DateTimeImmutable($ym . '-01T00:00:00Z');
        $nextMonth = $start->modify('first day of next month');
        $toTs = min($nextMonth->getTimestamp(), $nowTs);

        return new self(
            $ym,
            $start->format('Y-m-d\TH:i:s\Z'),
            gmdate('Y-m-d\TH:i:s\Z', $toTs),
            $start->format('Y-m-d'),
            $nextMonth->modify('-1 day')->format('Y-m-d'),
        );
    }

    /** @return list<string> 'YYYY-MM', newest first, including the current month */
    public static function lastMonths(int $count, int $nowTs): array
    {
        $months = [];
        $cursor = new \DateTimeImmutable(gmdate('Y-m', $nowTs) . '-01T00:00:00Z');
        for ($i = 0; $i < $count; $i++) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('-1 month');
        }

        return $months;
    }
}
```

- [ ] **Step 4: Run the full suite**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Status/MonthWindow.php plugins/revolut-payments-import/src/tests/Status/MonthWindowTest.php
git commit -m "feat(revolut): month window selection for the status page"
```

---

### Task 6: Status page handler and rendering in public.php

**Files:**
- Modify: `public.php`

**Interfaces:**
- Consumes: `MonthWindow::fromQuery/lastMonths`, `MonthlyStatusReport::build/summarize`, `StatusRow`, `IdempotencyStore::isProcessed`, `TransactionsApi::listAllTransactions`, `UcrmClient::get`, `UcrmOptionsManager` (`ucrmPublicUrl`), existing `renderPage()`/`renderHtml()`.
- Produces: `GET public.php?status=1&month=YYYY-MM` — Bulgarian, admin-only reconciliation page.

- [ ] **Step 1: Add imports** — in `public.php`'s `use` block add:

```php
use RevolutPaymentsImport\Status\MonthlyStatusReport;
use RevolutPaymentsImport\Status\MonthWindow;
use RevolutPaymentsImport\Status\StatusRow;
use RevolutPaymentsImport\Ucrm\UcrmClient;
```

- [ ] **Step 2: Add the route** — directly after the `?accounts` branch (after its closing `}`):

```php
// Admin-only status page: monthly reconciliation overview — every incoming
// Revolut transfer of the selected month with its UISP payment status.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['status'])) {
    handleStatusPage($config, $logger);

    return;
}
```

- [ ] **Step 3: Widen renderPage** — replace the `renderPage()` function signature and body-opening line so wide tables fit (default stays 40rem for the other pages):

```php
/** @param string $bodyHtml pre-escaped HTML */
function renderPage(string $title, string $bodyHtml, string $maxWidth = '40rem'): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:' . htmlspecialchars($maxWidth) . ';margin:4rem auto;padding:0 1rem;line-height:1.5">'
        . '<h2>' . htmlspecialchars($title) . '</h2>'
        . $bodyHtml
        . '</body></html>';
}
```

- [ ] **Step 4: Add the handler and helpers** — append to `public.php` (before `renderHtml`):

```php
/**
 * Monthly reconciliation page (Bulgarian UI): every incoming Revolut transfer
 * of the selected month with its UISP payment status. Admin-only — the page
 * exposes payment and client data.
 */
function handleStatusPage(PluginConfig $config, Logger $logger): void
{
    $user = null;
    try {
        $user = UcrmSecurity::create()->getUser();
    } catch (\Throwable $e) {
        $user = null;
    }
    if ($user === null || $user->isClient) {
        http_response_code(403);
        renderHtml('Забранен достъп', 'Влезте в UISP като администратор и презаредете страницата.');

        return;
    }

    if ($config->refreshToken() === null) {
        renderHtml('Не е свързано', 'Първо завършете оторизацията към Revolut (вижте лога на плъгина), после презаредете страницата.');

        return;
    }

    try {
        $monthParam = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : null;
        $window = MonthWindow::fromQuery($monthParam, time());

        $privateKey = (string) file_get_contents(__DIR__ . '/data/keys/private.pem');
        $tokenProvider = new TokenProvider(
            new RevolutClient(new Client(), $config->environment()),
            $config,
            new JwtClientAssertion(),
            $privateKey,
            time(),
        );
        $revolut = new RevolutClient(new Client(), $config->environment(), $tokenProvider->getAccessToken());
        $transactions = (new TransactionsApi($revolut))->listAllTransactions($window->fromIso, $window->toIso);

        $ucrm = SdkUcrmClient::create();
        $payments = fetchMonthPayments($ucrm, $window->fromDate, $window->toDate);

        $store = new IdempotencyStore(__DIR__ . '/data/processed.json');
        $report = new MonthlyStatusReport($config->accountIds());
        $rows = $report->build($transactions, $payments, [$store, 'isProcessed']);
        $summary = $report->summarize($rows);
        $clientNames = fetchClientNames($ucrm, $rows);
        $crmUrl = rtrim((string) (UcrmOptionsManager::create()->loadOptions()->ucrmPublicUrl ?? ''), '/');

        renderPage(
            'Revolut преводи — ' . monthLabelBg($window->ym),
            renderStatusBody($rows, $summary, $window, $clientNames, $crmUrl),
            '75rem',
        );
    } catch (\Throwable $e) {
        // Never reflect internals on a public endpoint.
        $logger->error('Status page error: ' . $e->getMessage());
        http_response_code(500);
        renderHtml('Грешка', 'Справката не можа да бъде заредена. Проверете лога на плъгина в UISP.');
    }
}

/** @return list<array<mixed>> all UISP payments created within [$fromDate, $toDate] */
function fetchMonthPayments(UcrmClient $ucrm, string $fromDate, string $toDate): array
{
    $all = [];
    for ($offset = 0; $offset < 100000; $offset += 500) {
        $page = array_values(array_filter($ucrm->get('payments', [
            'createdDateFrom' => $fromDate,
            'createdDateTo' => $toDate,
            'limit' => 500,
            'offset' => $offset,
        ]), 'is_array'));
        $all = array_merge($all, $page);
        if (count($page) < 500) {
            break;
        }
    }

    return $all;
}

/**
 * @param list<StatusRow> $rows
 * @return array<int,string> client id => display name, for rows with a client
 */
function fetchClientNames(UcrmClient $ucrm, array $rows): array
{
    $needed = [];
    foreach ($rows as $row) {
        if ($row->clientId !== null) {
            $needed[$row->clientId] = true;
        }
    }
    if ($needed === []) {
        return [];
    }

    $names = [];
    foreach ($ucrm->get('clients') as $client) {
        if (! is_array($client) || ! isset($client['id'])) {
            continue;
        }
        $id = (int) $client['id'];
        if (! isset($needed[$id])) {
            continue;
        }
        $company = trim((string) ($client['companyName'] ?? ''));
        $person = trim(trim((string) ($client['firstName'] ?? '')) . ' ' . trim((string) ($client['lastName'] ?? '')));
        $names[$id] = $company !== '' ? $company : ($person !== '' ? $person : '#' . $id);
    }

    return $names;
}

function monthLabelBg(string $ym): string
{
    $names = [
        'януари', 'февруари', 'март', 'април', 'май', 'юни',
        'юли', 'август', 'септември', 'октомври', 'ноември', 'декември',
    ];
    $month = (int) substr($ym, 5, 2);

    return ($names[$month - 1] ?? '?') . ' ' . substr($ym, 0, 4);
}

/**
 * @param list<StatusRow> $rows
 * @param array<string,array{count:int,amounts:array<string,float>}> $summary
 * @param array<int,string> $clientNames
 */
function renderStatusBody(array $rows, array $summary, MonthWindow $window, array $clientNames, string $crmUrl): string
{
    $statusMeta = [
        StatusRow::STATUS_ASSIGNED => ['✅ Разнесен', '#e6f4ea'],
        StatusRow::STATUS_UNASSIGNED => ['⚠️ Записан без клиент', '#fef7e0'],
        StatusRow::STATUS_SKIPPED => ['⏭ Пропуснат (ръчно плащане)', '#e8eaed'],
        StatusRow::STATUS_MISSING => ['❌ Липсва', '#fce8e6'],
    ];

    $options = '';
    foreach (MonthWindow::lastMonths(12, time()) as $ym) {
        $selected = $ym === $window->ym ? ' selected' : '';
        $options .= '<option value="' . htmlspecialchars($ym) . '"' . $selected . '>'
            . htmlspecialchars(monthLabelBg($ym)) . '</option>';
    }
    $previousYm = MonthWindow::lastMonths(2, time())[1];
    $html = '<form method="get" style="margin-bottom:1rem">'
        . '<input type="hidden" name="status" value="1">'
        . '<label>Месец: <select name="month">' . $options . '</select></label> '
        . '<button type="submit">Покажи</button>'
        . ' &nbsp; <a href="?status=1">Текущ месец</a> · '
        . '<a href="?status=1&amp;month=' . htmlspecialchars($previousYm) . '">Предходен месец</a>'
        . '</form>';

    $html .= '<p>';
    foreach ($statusMeta as $status => [$label, $color]) {
        $amounts = [];
        foreach ($summary[$status]['amounts'] as $currency => $sum) {
            $amounts[] = number_format($sum, 2, '.', ' ') . ' ' . $currency;
        }
        $html .= '<span style="background:' . $color . ';padding:2px 8px;border-radius:4px;margin-right:.5rem;display:inline-block;margin-bottom:.25rem">'
            . htmlspecialchars($label) . ': <strong>' . $summary[$status]['count'] . '</strong>'
            . ($amounts !== [] ? ' (' . htmlspecialchars(implode(', ', $amounts)) . ')' : '')
            . '</span>';
    }
    $html .= '</p>';

    if ($rows === []) {
        return $html . '<p>Няма входящи Revolut преводи за избрания месец.</p>';
    }

    $cells = '';
    foreach ($rows as $row) {
        [$label, $color] = $statusMeta[$row->status];
        $client = '&mdash;';
        if ($row->clientId !== null) {
            $name = htmlspecialchars($clientNames[$row->clientId] ?? ('#' . $row->clientId));
            $client = $crmUrl !== ''
                ? '<a href="' . htmlspecialchars($crmUrl . '/client/' . $row->clientId) . '">' . $name . '</a>'
                : $name;
        }
        $cells .= '<tr style="background:' . $color . '">'
            . '<td>' . htmlspecialchars($row->date) . '</td>'
            . '<td style="text-align:right">' . number_format($row->amount, 2, '.', ' ') . '</td>'
            . '<td>' . htmlspecialchars($row->currency) . '</td>'
            . '<td>' . htmlspecialchars($row->sender) . '</td>'
            . '<td>' . htmlspecialchars($row->reference) . '</td>'
            . '<td>' . htmlspecialchars($label) . '</td>'
            . '<td>' . $client . '</td>'
            . '</tr>';
    }
    $html .= '<table border="1" cellpadding="6" style="border-collapse:collapse;width:100%">'
        . '<tr><th>Дата</th><th>Сума</th><th>Валута</th><th>Подател</th><th>Основание</th><th>Статус</th><th>Клиент</th></tr>'
        . $cells
        . '</table>';

    return $html;
}
```

- [ ] **Step 5: Lint**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php -l public.php`
Expected: `No syntax errors detected in public.php`.

- [ ] **Step 6: Run the full suite (regression)**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 7: Commit**

```powershell
git add plugins/revolut-payments-import/src/public.php
git commit -m "feat(revolut): monthly reconciliation status page (?status=1, Bulgarian UI)"
```

---

### Task 7: Version bump, README, full verification

**Files:**
- Modify: `manifest.json` (version `1.5.0` → `1.6.0`)
- Modify: `plugins/revolut-payments-import/README.md`

- [ ] **Step 1: Bump manifest version** — in `manifest.json` change `"version": "1.5.0"` to `"version": "1.6.0"`.

- [ ] **Step 2: Add README section** — insert after the "Importing from specific accounts only" section:

```markdown
## Monthly status page (reconciliation overview)
Open the plugin's Public URL with `?status=1` (UCRM admin login required) to
see every incoming Revolut transfer for a selected month — current by default,
any of the last 12 via the dropdown (`&month=YYYY-MM`) — with its status in
UCRM/UISP:
- ✅ recorded and attached to a client (linked),
- ⚠️ recorded but unassigned (waiting for manual attachment),
- ⏭ skipped by the manual-payment duplicate guard,
- ❌ missing from UCRM (never imported).
Per-status counts and per-currency totals are shown above the table. Matching
uses the payment's `providerName`/`providerPaymentId` (stamped on every payment
the plugin creates since v1.6.0) and falls back to amount + date + `Revolut: `
note matching for payments imported by older versions. The page UI is in
Bulgarian. The transfer list honors the "Revolut accounts to import from"
filter — it shows exactly what the plugin imports.
```

- [ ] **Step 3: Full suite + lint of all touched entry points**

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit`
Expected: PASS.
Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php -l main.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```powershell
git add plugins/revolut-payments-import/src/manifest.json plugins/revolut-payments-import/README.md
git commit -m "feat(revolut): document status page, bump version to 1.6.0"
```

---

### Task 8: Rebuild the plugin zip

**Files:**
- Modify: `plugins/revolut-payments-import/revolut-payments-import.zip` (binary, regenerated)

- [ ] **Step 1: Pack** (runs `composer install --no-dev` inside `src`, then zips):

Run (PowerShell): `docker run --rm -v "C:\Users\rosen\UCRM-plugins:/repo" -w /repo ucrm-php-test:8.3 php pack-plugin.php revolut-payments-import`
Expected: composer validate OK, no "Unable to add file" errors, exit code 0.

- [ ] **Step 2: Restore dev dependencies** (packing stripped phpunit from vendor):

Run: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 composer install`
Then re-run the suite: `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 php vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 3: Verify only the zip changed**

Run: `git status --short`
Expected: only `plugins/revolut-payments-import/revolut-payments-import.zip` modified (composer.lock untouched).

- [ ] **Step 4: Commit**

```powershell
git add plugins/revolut-payments-import/revolut-payments-import.zip
git commit -m "chore(revolut): rebuild plugin zip for v1.6.0"
```
