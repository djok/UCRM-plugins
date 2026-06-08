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
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\SdkUcrmClient;
use RevolutPaymentsImport\Ucrm\UcrmPaymentGateway;
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
    $fromIso = gmdate('Y-m-d\TH:i:s\Z', $fromTs);
    $toIso = gmdate('Y-m-d\TH:i:s\Z', $now);

    $transactions = $transactionsApi->listTransactions($fromIso, $toIso, 1000);
    foreach ($transactions as $transaction) {
        $processor->processTransaction($transaction);
    }
    $logger->info(sprintf('main: reconciled %d transaction(s) in [%s, %s].', count($transactions), $fromIso, $toIso));

    // Advance the reconcile cursor with a 1h overlap (dedupe keeps it safe).
    $config->set('reconcileFrom', (string) ($now - 3600));
    $config->save();
} catch (\Throwable $e) {
    $logger->error('main: ' . $e->getMessage());
}
