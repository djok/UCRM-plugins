# Revolut Verified-Skip + Re-import (v1.9.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The status page verifies the „ръчно плащане" claim before showing ⏭ (otherwise shows a new 🗑 „Обработено, но липсва" status), and each 🗑 row gets an admin-only, HMAC-guarded „Добави наново" button that forgets the id and re-runs the standard import pipeline.

**Architecture:** Pure verification logic in `MonthlyStatusReport` (new optional resolver callable + manual-payment scan over the already-fetched month payments); one new mutator on `IdempotencyStore`; all web wiring in `public.php` (new POST action route placed before the webhook fallthrough — webhook JSON POSTs carry no form fields so `$_POST` is empty and they pass through unchanged).

**Tech Stack:** PHP 8.1, PHPUnit 10.5, Docker `ucrm-php-test:8.3`. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-07-06-revolut-gone-payments-design.md`

## Global Constraints

- Plugin root: `plugins/revolut-payments-import/src`; PHP paths below relative to it.
- **No local PHP.** All commands via Docker through the **PowerShell tool**:
  `docker run --rm -v "C:\Users\rosen\UCRM-plugins\plugins\revolut-payments-import\src:/app" -w /app ucrm-php-test:8.3 <command>`
- Manual payment definition (exact): `providerName !== UcrmPaymentGateway::PROVIDER_NAME` AND note NOT starting with `'Revolut: '` AND amount within `0.005` AND same `Y-m-d` date; client-filtered only when the resolver returns a client.
- HMAC: `hash_hmac('sha256', 'reimport:' . $txId, signingSecret)`, verified with `hash_equals`; missing secret → no tokens, action rejected.
- PSR-12, `strict_types`, final classes; UI strings Bulgarian; commits `<type>(revolut): <description>`, no attribution footers.
- New version: **1.9.0**.

---

### Task 1: IdempotencyStore::forget

**Files:**
- Modify: `src/Support/IdempotencyStore.php`
- Test: `tests/Support/IdempotencyStoreTest.php`

**Interfaces:**
- Produces: `IdempotencyStore::forget(string $id): void` — removes the id and flushes; silent no-op when absent.

- [ ] **Step 1: Write the failing tests** — append to `tests/Support/IdempotencyStoreTest.php` (inside the class, following its existing temp-file conventions — read the file first and reuse its path helper/fixtures):

```php
    public function testForgetRemovesIdAndPersists(): void
    {
        $path = sys_get_temp_dir() . '/revolut-idempotency-forget-' . uniqid() . '.json';
        $store = new IdempotencyStore($path);
        $store->markProcessed('tx-1');
        $store->markProcessed('tx-2');

        $store->forget('tx-1');

        self::assertFalse($store->isProcessed('tx-1'));
        self::assertTrue($store->isProcessed('tx-2'));
        $reloaded = new IdempotencyStore($path);
        self::assertFalse($reloaded->isProcessed('tx-1'));
        self::assertTrue($reloaded->isProcessed('tx-2'));
        @unlink($path);
    }

    public function testForgetUnknownIdIsHarmless(): void
    {
        $path = sys_get_temp_dir() . '/revolut-idempotency-forget2-' . uniqid() . '.json';
        $store = new IdempotencyStore($path);
        $store->markProcessed('tx-1');

        $store->forget('tx-unknown');

        self::assertTrue($store->isProcessed('tx-1'));
        @unlink($path);
    }
```

- [ ] **Step 2: Run to verify failure** — `--filter IdempotencyStoreTest` → ERROR undefined method `forget`.

- [ ] **Step 3: Implement** — add to `src/Support/IdempotencyStore.php` after `markProcessed()`:

```php
    /**
     * Removes an id so the transaction can be imported again — used by the
     * status page's explicit re-import action when the payment was deleted
     * in UISP. Reconciliation itself never forgets ids.
     */
    public function forget(string $id): void
    {
        if (! isset($this->processed[$id])) {
            return;
        }
        unset($this->processed[$id]);
        $this->flush();
    }
```

- [ ] **Step 4: Full suite** — expect 112 tests passing.

- [ ] **Step 5: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Support/IdempotencyStore.php plugins/revolut-payments-import/src/tests/Support/IdempotencyStoreTest.php
git commit -m "feat(revolut): IdempotencyStore::forget for explicit re-imports"
```

---

### Task 2: STATUS_GONE + verified skip in MonthlyStatusReport

**Files:**
- Modify: `src/Status/StatusRow.php`, `src/Status/MonthlyStatusReport.php`
- Test: `tests/Status/MonthlyStatusReportTest.php`

**Interfaces:**
- Produces: `StatusRow::STATUS_GONE = 'gone'`; `build(array $transactions, array $payments, callable $isProcessed, ?callable $resolveClient = null)` where `$resolveClient` is `fn(string $sender): ?int`; `summarize()` returns all FIVE status keys.

- [ ] **Step 1: Add the constant** — in `src/Status/StatusRow.php` after `STATUS_SKIPPED`:

```php
    /** Processed in the past but the payment no longer exists in UISP
     * (deleted or lost) — re-importable from the status page. */
    public const STATUS_GONE = 'gone';
```

- [ ] **Step 2: Write the failing tests** — in `tests/Status/MonthlyStatusReportTest.php`:

First UPDATE two existing tests to the new semantics:
- `testProcessedTransferWithoutPaymentIsSkippedStatus` → rename to `testProcessedTransferWithoutAnyPaymentIsGone` and assert `StatusRow::STATUS_GONE` (no manual payment exists in its fixture, so the old blanket ⏭ is now 🗑).
- `testSummarizeCountsAndSumsPerCurrency` → also assert `self::assertSame(0, $summary[StatusRow::STATUS_GONE]['count']);`.

Then append the new tests:

```php
    public function testProcessedRowWithManualPaymentForResolvedClientIsSkipped(): void
    {
        $manual = $this->payment(['clientId' => 42, 'note' => 'платено на каса', 'providerName' => null, 'providerPaymentId' => null]);
        $resolver = static fn (string $sender): ?int => 42;

        $row = (new MonthlyStatusReport())->build(
            [$this->tx('tx-m')],
            [$manual],
            static fn (string $id): bool => true,
            $resolver,
        )[0];

        self::assertSame(StatusRow::STATUS_SKIPPED, $row->status);
        self::assertSame(42, $row->clientId);
    }

    public function testProcessedRowWithoutPaymentIsGoneWithExpectedClient(): void
    {
        $resolver = static fn (string $sender): ?int => 42;

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-g')], [], static fn (string $id): bool => true, $resolver)[0];

        self::assertSame(StatusRow::STATUS_GONE, $row->status);
        self::assertSame(42, $row->clientId);
    }

    public function testManualPaymentOfAnotherClientDoesNotVerifyTheSkip(): void
    {
        $foreign = $this->payment(['clientId' => 7, 'note' => 'каса', 'providerName' => null, 'providerPaymentId' => null]);
        $resolver = static fn (string $sender): ?int => 42;

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-g2')], [$foreign], static fn (string $id): bool => true, $resolver)[0];

        self::assertSame(StatusRow::STATUS_GONE, $row->status);
        self::assertSame(42, $row->clientId);
    }

    public function testPluginCreatedPaymentDoesNotCountAsManual(): void
    {
        // Same amount/date but created by the plugin for ANOTHER transfer.
        $pluginPayment = $this->payment(['clientId' => 42, 'providerName' => 'Revolut', 'providerPaymentId' => 'tx-OTHER']);

        $row = (new MonthlyStatusReport())->build(
            [$this->tx('tx-g3')],
            [$pluginPayment],
            static fn (string $id): bool => true,
            static fn (string $sender): ?int => 42,
        )[0];

        self::assertSame(StatusRow::STATUS_GONE, $row->status);
    }

    public function testUnresolvedSenderAcceptsAnyManualPaymentAsSkipReason(): void
    {
        $manual = $this->payment(['clientId' => 7, 'note' => 'каса', 'providerName' => null, 'providerPaymentId' => null]);

        $row = (new MonthlyStatusReport())->build([$this->tx('tx-u')], [$manual], static fn (string $id): bool => true)[0];

        self::assertSame(StatusRow::STATUS_SKIPPED, $row->status);
        self::assertSame(7, $row->clientId);
    }
```

- [ ] **Step 3: Run to verify failure** — `--filter MonthlyStatusReportTest` → failures (STATUS_GONE undefined / wrong statuses).

- [ ] **Step 4: Implement** — in `src/Status/MonthlyStatusReport.php`:

a. Signature: `public function build(array $transactions, array $payments, callable $isProcessed, ?callable $resolveClient = null): array` — extend the docblock with `@param callable(string):(int|null)|null $resolveClient sender → expected client id`.

b. Hoist the sender: where the row is built, compute `$sender = $this->senderFromLeg($leg);` once (before the status resolution) and reuse it in the `StatusRow` constructor.

c. Replace the `match (true)` status resolution with:

```php
            $clientId = $payment !== null && isset($payment['clientId']) && $payment['clientId'] !== null
                ? (int) $payment['clientId']
                : null;
            if ($payment !== null) {
                $status = $clientId !== null ? StatusRow::STATUS_ASSIGNED : StatusRow::STATUS_UNASSIGNED;
            } elseif ($isProcessed($id)) {
                // Processed without an importable payment: either the duplicate
                // guard saw a manually entered payment back then, or the imported
                // payment was later deleted in UISP. Verify which is true.
                $expectedClientId = $resolveClient !== null && $sender !== '' ? $resolveClient($sender) : null;
                $manual = $this->findManualPayment($payments, $date, $amount, $expectedClientId);
                if ($manual !== null) {
                    $status = StatusRow::STATUS_SKIPPED;
                    $clientId = isset($manual['clientId']) && $manual['clientId'] !== null ? (int) $manual['clientId'] : null;
                } else {
                    $status = StatusRow::STATUS_GONE;
                    $clientId = $expectedClientId;
                }
            } else {
                $status = StatusRow::STATUS_MISSING;
            }
```

d. New private method:

```php
    /**
     * A manually entered payment (not created by this plugin) with the same
     * amount and date — the duplicate guard's skip reason. When the expected
     * client is known, only that client's payments count.
     *
     * @param list<array<mixed>> $payments
     * @return array<mixed>|null
     */
    private function findManualPayment(array $payments, string $date, float $amount, ?int $expectedClientId): ?array
    {
        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }
            if (($payment['providerName'] ?? null) === UcrmPaymentGateway::PROVIDER_NAME) {
                continue;
            }
            if (str_starts_with((string) ($payment['note'] ?? ''), self::LEGACY_NOTE_PREFIX)) {
                continue;
            }
            if (abs((float) ($payment['amount'] ?? 0.0) - $amount) >= self::AMOUNT_EPSILON) {
                continue;
            }
            if (substr((string) ($payment['createdDate'] ?? ''), 0, 10) !== $date) {
                continue;
            }
            $paymentClientId = isset($payment['clientId']) && $payment['clientId'] !== null ? (int) $payment['clientId'] : null;
            if ($expectedClientId !== null && $paymentClientId !== $expectedClientId) {
                continue;
            }

            return $payment;
        }

        return null;
    }
```

e. `summarize()`: add `StatusRow::STATUS_GONE` to the `$statuses` array (after `STATUS_SKIPPED`).

- [ ] **Step 5: Full suite** — expect 117 tests passing.

- [ ] **Step 6: Commit**

```powershell
git add plugins/revolut-payments-import/src/src/Status plugins/revolut-payments-import/src/tests/Status
git commit -m "feat(revolut): verify manual-payment skips, introduce gone status"
```

---

### Task 3: Re-import action + status page wiring in public.php

**Files:**
- Modify: `public.php`

**Interfaces:**
- Consumes: `IdempotencyStore::forget`, `buildProcessor()` (existing), `TransactionsApi::getTransaction`, `MonthlyStatusReport::build(..., $resolveClient)`, `StatusRow::STATUS_GONE`, `ClientMatcher` (already imported in public.php).
- Produces: POST `public.php` with `action=reimport&tx&token&month`; GONE badge + „Добави наново" button; success alert on `?reimported=1`.

- [ ] **Step 1: Add the POST route** — in `public.php`, immediately AFTER the GET catch-all status route block and BEFORE the `$rawBody = ...` webhook section:

```php
// Admin-only action from the status page: re-import one transaction whose
// payment was deleted in UISP (its id stays in processed.json, so normal
// reconciliation deliberately skips it). Webhook POSTs carry a JSON body,
// never form fields, so they fall through untouched.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reimport') {
    handleReimportAction($config, $logger);

    return;
}
```

- [ ] **Step 2: Add the handler and token helper** — append near `handleStatusPage()`:

```php
/**
 * Explicit re-import of one transaction (status page „Добави наново"): forgets
 * the idempotency record and runs the standard pipeline — original date,
 * provider stamping, IBAN→name client matching. Admin session + HMAC required.
 */
function handleReimportAction(PluginConfig $config, Logger $logger): void
{
    $user = null;
    try {
        $user = UcrmSecurity::create()->getUser();
    } catch (\Throwable $e) {
        $user = null;
    }
    if ($user === null || $user->isClient) {
        http_response_code(403);
        renderHtml('Забранен достъп', 'Влезте в UISP като администратор.');

        return;
    }

    $txId = isset($_POST['tx']) && is_string($_POST['tx']) ? trim($_POST['tx']) : '';
    $token = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
    $month = isset($_POST['month']) && is_string($_POST['month']) ? $_POST['month'] : '';
    $expected = statusActionToken($txId, $config);
    if ($expected === null || ! hash_equals($expected, $token)) {
        http_response_code(403);
        renderHtml('Невалидна заявка', 'Невалидна защитна отметка — презаредете статус страницата и опитайте отново.');

        return;
    }

    try {
        $privateKey = (string) file_get_contents(__DIR__ . '/data/keys/private.pem');
        $tokenProvider = new TokenProvider(
            new RevolutClient(new Client(), $config->environment()),
            $config,
            new JwtClientAssertion(),
            $privateKey,
            time(),
        );
        $revolut = new RevolutClient(new Client(), $config->environment(), $tokenProvider->getAccessToken());
        $transaction = (new TransactionsApi($revolut))->getTransaction($txId);
        if ($transaction === null) {
            renderHtml('Не е намерена', 'Revolut не върна такава транзакция. Проверете лога на плъгина.');

            return;
        }

        // Forget FIRST (flushes to disk), then build the processor — its own
        // IdempotencyStore instance loads the file fresh and will record anew.
        (new IdempotencyStore(__DIR__ . '/data/processed.json'))->forget($txId);
        buildProcessor($config, $logger)->processTransaction($transaction);
        $logger->info('Re-import: transaction ' . $txId . ' re-processed on admin request.');

        header(
            'Location: ?status=1&reimported=1' . ($month !== '' ? '&month=' . rawurlencode($month) : ''),
            true,
            303,
        );
    } catch (\Throwable $e) {
        $logger->error('Re-import error for ' . $txId . ': ' . $e->getMessage());
        http_response_code(500);
        renderHtml('Грешка', 'Реимпортът не успя. Проверете лога на плъгина в UISP.');
    }
}

/** HMAC guarding status-page actions; null while unconfigured (no secret). */
function statusActionToken(string $txId, PluginConfig $config): ?string
{
    $secret = $config->signingSecret();
    if ($txId === '' || $secret === null) {
        return null;
    }

    return hash_hmac('sha256', 'reimport:' . $txId, $secret);
}
```

- [ ] **Step 3: Wire the resolver + tokens in `handleStatusPage()`** — replace the block from `$store = new IdempotencyStore(...)` through the `renderUispPage(...)` call with:

```php
        $store = new IdempotencyStore(__DIR__ . '/data/processed.json');
        $report = new MonthlyStatusReport($config->accountIds());

        // Sender → expected client, memoized per normalized sender (each miss
        // costs a full GET clients through ClientMatcher).
        $matcher = new ClientMatcher($ucrm);
        $resolveCache = [];
        $resolveClient = static function (string $sender) use ($matcher, &$resolveCache): ?int {
            $key = ClientMatcher::normalizeIban($sender);
            if ($key === '') {
                return null;
            }
            if (! array_key_exists($key, $resolveCache)) {
                $client = $matcher->findClientByIban($sender);
                $resolveCache[$key] = isset($client['id']) ? (int) $client['id'] : null;
            }

            return $resolveCache[$key];
        };

        $rows = $report->build($transactions, $payments, [$store, 'isProcessed'], $resolveClient);
        $summary = $report->summarize($rows);
        $clientNames = fetchClientNames($ucrm, $rows);

        $reimportTokens = [];
        foreach ($rows as $row) {
            if ($row->status === StatusRow::STATUS_GONE) {
                $token = statusActionToken($row->transactionId, $config);
                if ($token !== null) {
                    $reimportTokens[$row->transactionId] = $token;
                }
            }
        }

        $flash = isset($_GET['reimported'])
            ? '<div class="alert alert-success py-2">Транзакцията беше обработена наново — вижте статуса на реда по-долу.</div>'
            : '';

        renderUispPage(
            'Revolut преводи — ' . monthLabelBg($window->ym),
            $flash . renderStatusBody($rows, $summary, $window, $clientNames, $crmUrl, $reimportTokens),
            $crmUrl,
        );
```

- [ ] **Step 4: Extend `renderStatusBody()`** — signature gains a trailing param `array $reimportTokens = []` (update its docblock: `@param array<string,string> $reimportTokens tx id => HMAC for GONE rows`). Add to `$statusMeta` after the SKIPPED entry:

```php
        StatusRow::STATUS_GONE => ['🗑 Обработено, но липсва', 'info'],
```

In the row loop, before building `$cells`, compute the action form and append it inside the status `<td>`:

```php
        $action = '';
        if ($row->status === StatusRow::STATUS_GONE && isset($reimportTokens[$row->transactionId])) {
            $action = ' <form method="post" class="d-inline ml-2">'
                . '<input type="hidden" name="action" value="reimport">'
                . '<input type="hidden" name="tx" value="' . htmlspecialchars($row->transactionId) . '">'
                . '<input type="hidden" name="token" value="' . htmlspecialchars($reimportTokens[$row->transactionId]) . '">'
                . '<input type="hidden" name="month" value="' . htmlspecialchars($window->ym) . '">'
                . '<button type="submit" class="btn btn-outline-primary btn-sm py-0" '
                . 'title="Маха записа от историята на плъгина и внася плащането наново от Revolut — с оригиналната дата и автоматично разпознат клиент">'
                . 'Добави наново</button>'
                . '</form>';
        }
```

and change the status cell to `'<td>' . htmlspecialchars($label) . $action . '</td>'`.

- [ ] **Step 5: Lint + regression**

`php -l public.php` → clean; full suite → 117 tests passing.

- [ ] **Step 6: Commit**

```powershell
git add plugins/revolut-payments-import/src/public.php
git commit -m "feat(revolut): re-import action for deleted payments on the status page"
```

---

### Task 4: README, version 1.9.0, zip

**Files:**
- Modify: `src/manifest.json` (`"version": "1.8.0"` → `"1.9.0"`), `plugins/revolut-payments-import/README.md`

- [ ] **Step 1: README** — in the "Monthly status page" section, replace the four-bullet status list with five bullets and add the re-import note:

```markdown
- ✅ recorded and attached to a client (linked),
- ⚠️ recorded but unassigned (waiting for manual attachment),
- ⏭ skipped because the matched client really has a manual payment with the
  same amount and date (verified against the month's payments),
- 🗑 processed in the past but the payment no longer exists in UISP (deleted
  or lost) — the row shows the client it would be attached to and offers an
  admin-only **„Добави наново"** button that re-imports the transaction from
  Revolut with its original date and automatic sender matching,
- ❌ missing from UCRM (never imported).
Deleted payments are never re-imported automatically — only via the button.
```

- [ ] **Step 2: Manifest bump; validate** — `php -r "json_decode(file_get_contents('manifest.json'), true, 512, JSON_THROW_ON_ERROR); echo 'ok';"`.

- [ ] **Step 3: Full suite + lint main.php/public.php** — all clean.

- [ ] **Step 4: Commit**

```powershell
git add plugins/revolut-payments-import/src/manifest.json plugins/revolut-payments-import/README.md
git commit -m "feat(revolut): document verified skips and re-import, bump to 1.9.0"
```

- [ ] **Step 5: Repack zip** — `docker run --rm -v "C:\Users\rosen\UCRM-plugins:/repo" -w /repo ucrm-php-test:8.3 php pack-plugin.php revolut-payments-import`; restore dev deps (`composer install`); re-run suite; `git status --short` → only the zip; commit `chore(revolut): rebuild plugin zip for v1.9.0`.
