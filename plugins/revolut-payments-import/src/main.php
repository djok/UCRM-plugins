<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ubnt\UcrmPluginSdk\Service\PluginLogManager;
use RevolutPaymentsImport\Auth\JwtClientAssertion;
use RevolutPaymentsImport\Auth\TokenProvider;
use RevolutPaymentsImport\Config\PluginConfig;
use RevolutPaymentsImport\Matching\ClientMatcher;
use RevolutPaymentsImport\Revolut\CounterpartyApi;
use RevolutPaymentsImport\Revolut\HttpClientFactory;
use RevolutPaymentsImport\Revolut\RevolutApiException;
use RevolutPaymentsImport\Revolut\RevolutClient;
use RevolutPaymentsImport\Revolut\TransactionsApi;
use RevolutPaymentsImport\Revolut\WebhooksApi;
use RevolutPaymentsImport\Statement\StatementCsvParser;
use RevolutPaymentsImport\Statement\StatementImporter;
use RevolutPaymentsImport\Statement\StatementReMatcher;
use RevolutPaymentsImport\Support\FileLock;
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Support\StageRunner;
use RevolutPaymentsImport\Support\SyncHealthStore;
use RevolutPaymentsImport\Ucrm\ClientAccountLearner;
use RevolutPaymentsImport\Ucrm\PaymentFinder;
use RevolutPaymentsImport\Ucrm\PaymentUpdater;
use RevolutPaymentsImport\Ucrm\SdkUcrmClient;
use RevolutPaymentsImport\Ucrm\UcrmPaymentGateway;
use RevolutPaymentsImport\Ucrm\UcrmPaymentLookup;
use RevolutPaymentsImport\Webhook\EventProcessor;

chdir(__DIR__);

const STATEMENT_MAX_ATTEMPTS = 5;

$logManager = PluginLogManager::create();
$logger = new Logger([$logManager, 'appendLog']);
$config = PluginConfig::fromFile(__DIR__ . '/data/config.json');

$now = time();
$health = new SyncHealthStore(__DIR__ . '/data/health.json');
$runner = new StageRunner($logger, $health, $now);
$ucrm = SdkUcrmClient::create();

// Each stage runs in isolation (StageRunner). A Revolut outage — blocked account,
// 401/403/429, or the failed-events 500 seen in production — fails only its own
// stage; the others still run. This is what keeps the Revolut-INDEPENDENT
// statement CSV import (the operator's manual fallback) alive during exactly the
// outage it exists for. The previous single try/catch coupled everything, so one
// Revolut error killed the whole run.
$revolut = null;
$transactionsApi = null;
$processor = null;

if ($config->refreshToken() !== null && $config->webhookId() !== null) {
    $authed = $runner->run('revolut-auth', function () use ($config, $ucrm, $logger, $now, &$revolut, &$transactionsApi, &$processor): void {
        $privateKey = (string) file_get_contents(__DIR__ . '/data/keys/private.pem');
        $tokenProvider = new TokenProvider(
            new RevolutClient(HttpClientFactory::create(), $config->environment()),
            $config,
            new JwtClientAssertion(),
            $privateKey,
            $now,
        );
        $accessToken = $tokenProvider->getAccessToken();

        $revolut = new RevolutClient(HttpClientFactory::create(), $config->environment(), $accessToken);
        $transactionsApi = new TransactionsApi($revolut);
        $processor = new EventProcessor(
            $transactionsApi,
            new CounterpartyApi($revolut),
            new ClientMatcher($ucrm),
            new UcrmPaymentGateway($ucrm, (string) $config->paymentMethodName()),
            new IdempotencyStore(__DIR__ . '/data/processed.json'),
            $logger,
            $config->accountIds(),
            new FileLock(__DIR__ . '/data/import.lock'),
        );
    });

    if ($authed && $revolut !== null && $transactionsApi !== null && $processor !== null) {
        // 1) Replay failed webhook deliveries (21-day window upstream).
        $runner->run('replay-failed-events', function () use ($revolut, $config, $processor, $logger): void {
            $failed = (new WebhooksApi($revolut))->failedEvents((string) $config->webhookId(), 1000);
            foreach ($failed as $failedEvent) {
                $payload = $failedEvent['payload'] ?? null;
                if (is_array($payload)) {
                    processOne($logger, static fn () => $processor->processEvent($payload), 'failed event');
                }
            }
            $logger->info(sprintf('main: replayed %d failed webhook event(s).', count($failed)));
        });

        // 2) Reconcile recent transactions (safety net for missed webhooks).
        // Skip it if replay already hit a Revolut transport/auth error this run —
        // reconcile calls the same API and would only add load during an outage /
        // rate limit (and churn the health record). statement-import still runs.
        $reconciled = false;
        if ($runner->revolutUnavailable()) {
            $logger->info('main: skipping reconcile — Revolut was unavailable earlier this run.');
        } else {
            $reconciled = $runner->run('reconcile', function () use ($config, $transactionsApi, $processor, $logger, $now): void {
            $fromTs = $config->reconcileFrom() ?? ($now - 7 * 24 * 3600);

            // One-time historical backfill: widen the window when a "Backfill from
            // date" is set and not yet done. Idempotency keeps re-runs safe.
            $backfillFrom = $config->backfillFrom();
            $backfillTs = $backfillFrom !== null ? strtotime($backfillFrom) : false;
            $usingBackfill = is_int($backfillTs) && $backfillTs < $fromTs && $config->backfillDone() !== $backfillFrom;
            if ($usingBackfill) {
                $fromTs = $backfillTs;
                $logger->info(sprintf('main: backfilling history from %s.', (string) $backfillFrom));
            }

            $fromIso = gmdate('Y-m-d\TH:i:s\Z', $fromTs);
            $toIso = gmdate('Y-m-d\TH:i:s\Z', $now);

            $transactions = $transactionsApi->listAllTransactions($fromIso, $toIso);
            foreach ($transactions as $transaction) {
                $txId = is_array($transaction) && isset($transaction['id']) ? (string) $transaction['id'] : '?';
                processOne($logger, static fn () => $processor->processTransaction($transaction), 'transaction ' . $txId);
            }
            $logger->info(sprintf('main: reconciled %d transaction(s) in [%s, %s].', count($transactions), $fromIso, $toIso));

            // Advance the reconcile cursor with a 1h overlap (dedupe keeps it safe).
            if ($usingBackfill) {
                $config->set('backfillDone', $backfillFrom);
                $logger->info('main: backfill complete — it will not run again unless the date is changed.');
            }
            $config->set('reconcileFrom', (string) ($now - 3600));
            $config->save();
            });
        }

        if ($reconciled) {
            $health->recordSuccess($now);
        }
    }
} else {
    $logger->info('main: Revolut not fully configured yet — skipping Revolut stages.');
}

// 3) Statement CSV import — needs only UISP and the uploaded file, so it runs
// EVERY time regardless of Revolut's health. Re-runs only when the file changes,
// or (up to STATEMENT_MAX_ATTEMPTS) while attaching existing payments keeps failing.
// When Revolut is reachable, each new row is verified against the API so an
// internal hold/"Release" move is never imported as a customer payment.
$runner->run('statement-import', function () use ($config, $ucrm, $logger, $transactionsApi): void {
    $statementFile = $config->statementCsv();
    if ($statementFile === null) {
        return;
    }
    $path = __DIR__ . '/data/files/' . basename($statementFile);
    if (! is_file($path)) {
        $logger->error('main: statement file not found: ' . $path);

        return;
    }
    $content = (string) file_get_contents($path);
    $hash = md5($content);
    if ($config->statementDone() === $hash) {
        return;
    }

    $rows = (new StatementCsvParser())->parse($content);
    $ucrmPayments = new PaymentFinder($ucrm, $logger);
    $reMatcher = new StatementReMatcher(
        $ucrmPayments,
        new PaymentUpdater($ucrm, $logger),
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
        $transactionsApi,
        new FileLock(__DIR__ . '/data/import.lock'),
    );
    $imported = $importer->import($rows);
    $logger->info(sprintf('main: statement import — %d row(s) parsed, %d payment(s) imported.', count($rows), $imported));

    // A failed attach is safe to retry (the re-matcher skips payments that already
    // have a client), so keep the file pending instead of marking it done — but
    // bounded, so a permanently failing attach cannot re-run the file forever.
    $failures = $importer->reMatchFailures();
    $attempt = ($config->statementRetryHash() === $hash ? $config->statementRetryCount() : 0) + 1;
    if ($failures > 0 && $attempt < STATEMENT_MAX_ATTEMPTS) {
        $config->set('statementRetryHash', $hash);
        $config->set('statementRetryCount', (string) $attempt);
        $config->save();
        $logger->error(sprintf(
            'main: statement import — %d attach(es) failed; the statement will be retried on the next run (attempt %d/%d).',
            $failures,
            $attempt,
            STATEMENT_MAX_ATTEMPTS,
        ));

        return;
    }
    if ($failures > 0) {
        $logger->error(sprintf(
            'main: statement import — %d attach(es) still failing after %d attempts; giving up on this file — attach those payments manually.',
            $failures,
            $attempt,
        ));
    }
    $config->set('statementDone', $hash);
    $config->set('statementRetryHash', null);
    $config->set('statementRetryCount', null);
    $config->save();
});

/**
 * Runs one per-item operation inside a stage loop. A single missing resource
 * (Revolut 404 for a deleted counterparty) is logged and skipped; any other
 * Revolut failure (401/403/429/5xx/connect) is re-thrown so the STAGE aborts —
 * continuing would just hammer more failing requests against the rate limit.
 */
function processOne(Logger $logger, callable $op, string $what): void
{
    try {
        $op();
    } catch (RevolutApiException $e) {
        if ($e->statusCode === 404) {
            $logger->error('main: ' . $what . ' skipped — ' . $e->getMessage());

            return;
        }

        throw $e;
    } catch (\Throwable $e) {
        $logger->error('main: ' . $what . ' processing error: ' . $e->getMessage());
    }
}
