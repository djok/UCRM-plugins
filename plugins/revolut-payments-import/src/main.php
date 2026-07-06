<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use Ubnt\UcrmPluginSdk\Service\PluginLogManager;
use RevolutPaymentsImport\Auth\JwtClientAssertion;
use RevolutPaymentsImport\Auth\TokenProvider;
use RevolutPaymentsImport\Config\PluginConfig;
use RevolutPaymentsImport\Matching\ClientMatcher;
use RevolutPaymentsImport\Revolut\CounterpartyApi;
use RevolutPaymentsImport\Revolut\RevolutClient;
use RevolutPaymentsImport\Revolut\TransactionsApi;
use RevolutPaymentsImport\Revolut\WebhooksApi;
use RevolutPaymentsImport\Statement\StatementCsvParser;
use RevolutPaymentsImport\Statement\StatementImporter;
use RevolutPaymentsImport\Statement\StatementReMatcher;
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\ClientAccountLearner;
use RevolutPaymentsImport\Ucrm\PaymentFinder;
use RevolutPaymentsImport\Ucrm\PaymentUpdater;
use RevolutPaymentsImport\Ucrm\SdkUcrmClient;
use RevolutPaymentsImport\Ucrm\UcrmPaymentGateway;
use RevolutPaymentsImport\Ucrm\UcrmPaymentLookup;
use RevolutPaymentsImport\Webhook\EventProcessor;

chdir(__DIR__);

$logManager = PluginLogManager::create();
$logger = new Logger([$logManager, 'appendLog']);
$config = PluginConfig::fromFile(__DIR__ . '/data/config.json');

if ($config->refreshToken() === null || $config->webhookId() === null) {
    $logger->info('main: plugin not fully configured yet; nothing to do.');

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
    $accessToken = $tokenProvider->getAccessToken();

    $revolut = new RevolutClient(new Client(), $config->environment(), $accessToken);
    $transactionsApi = new TransactionsApi($revolut);
    $ucrm = SdkUcrmClient::create();

    $processor = new EventProcessor(
        $transactionsApi,
        new CounterpartyApi($revolut),
        new ClientMatcher($ucrm),
        new UcrmPaymentGateway($ucrm, (string) $config->paymentMethodName()),
        new IdempotencyStore(__DIR__ . '/data/processed.json'),
        $logger,
        $config->accountIds(),
    );

    // 1) Replay failed webhook deliveries (21-day window upstream).
    $failed = (new WebhooksApi($revolut))->failedEvents((string) $config->webhookId(), 1000);
    foreach ($failed as $failedEvent) {
        $payload = $failedEvent['payload'] ?? null;
        if (is_array($payload)) {
            $processor->processEvent($payload);
        }
    }
    $logger->info(sprintf('main: replayed %d failed webhook event(s).', count($failed)));

    // 2) Reconcile recent transactions (safety net for missed webhooks).
    $now = time();
    $fromTs = $config->reconcileFrom() ?? ($now - 7 * 24 * 3600);

    // One-time historical backfill: when a "Backfill from date" is set and not yet
    // done, widen the window back to that date. Idempotency keeps re-runs safe.
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
        $processor->processTransaction($transaction);
    }
    $logger->info(sprintf('main: reconciled %d transaction(s) in [%s, %s].', count($transactions), $fromIso, $toIso));

    // Advance the reconcile cursor with a 1h overlap (dedupe keeps it safe).
    if ($usingBackfill) {
        $config->set('backfillDone', $backfillFrom);
        $logger->info('main: backfill complete — it will not run again unless the date is changed.');
    }
    $config->set('reconcileFrom', (string) ($now - 3600));
    $config->save();

    // 3) Statement CSV import: the statement carries the sender IBAN + name for
    // every incoming transfer (the API often does not), so an uploaded export
    // yields Paysera-grade matching. Re-runs only when the file content changes.
    $statementFile = $config->statementCsv();
    if ($statementFile !== null) {
        $path = __DIR__ . '/data/files/' . basename($statementFile);
        if (is_file($path)) {
            $content = (string) file_get_contents($path);
            $hash = md5($content);
            if ($config->statementDone() !== $hash) {
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
                );
                $imported = $importer->import($rows);
                $config->set('statementDone', $hash);
                $config->save();
                $logger->info(sprintf('main: statement import — %d row(s) parsed, %d payment(s) imported.', count($rows), $imported));
            }
        } else {
            $logger->error('main: statement file not found: ' . $path);
        }
    }
} catch (\Throwable $e) {
    $logger->error('main: ' . $e->getMessage());
}
