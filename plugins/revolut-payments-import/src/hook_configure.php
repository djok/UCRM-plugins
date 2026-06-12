<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ubnt\UcrmPluginSdk\Service\PluginLogManager;
use Ubnt\UcrmPluginSdk\Service\UcrmOptionsManager;
use RevolutPaymentsImport\Auth\JwtClientAssertion;
use RevolutPaymentsImport\Auth\TokenProvider;
use RevolutPaymentsImport\Config\PluginConfig;
use RevolutPaymentsImport\Revolut\AccountsApi;
use RevolutPaymentsImport\Revolut\RevolutClient;
use RevolutPaymentsImport\Revolut\WebhooksApi;

$logManager = PluginLogManager::create();
$config = PluginConfig::fromFile(__DIR__ . '/data/config.json');

// 1) Require base credentials.
if ($config->clientId() === null || $config->redirectUri() === null) {
    $logManager->appendLog('[configure] Set Environment, ClientID and redirect URI, then Save. (See install log for STEP 1.)');
    return;
}

$privateKeyPath = __DIR__ . '/data/keys/private.pem';
if (! is_file($privateKeyPath)) {
    $logManager->appendLog('[configure] ERROR: missing data/keys/private.pem — reinstall the plugin to regenerate keys.');
    return;
}
$privateKeyPem = (string) file_get_contents($privateKeyPath);

$client = new RevolutClient(new \GuzzleHttp\Client(), $config->environment());
$tokenProvider = new TokenProvider($client, $config, new JwtClientAssertion(), $privateKeyPem, time());

// 2) If we have no refresh token, we need consent. If an auth code was pasted, exchange it.
if ($config->refreshToken() === null) {
    $authCode = $config->authCode();
    if ($authCode === null) {
        $logManager->appendLog('==================== REVOLUT SETUP — STEP 2 ====================');
        $logManager->appendLog('Open this URL, authorize access, then copy the "code" query parameter from the redirect URL');
        $logManager->appendLog('and paste it into the "Authorization code" field, then Save:');
        $logManager->appendLog(consentUrl($config));
        return;
    }

    try {
        $tokenProvider->exchangeAuthorizationCode($authCode);
        // Clear the one-time code so it is not reused.
        $config->set('authCode', null);
        $config->save();
        $logManager->appendLog('[configure] Obtained tokens from authorization code.');
    } catch (\Throwable $e) {
        // Codes are one-time and expire in ~2 minutes — retrying a failed code on the
        // next save is pointless, so clear it and ask for a fresh consent instead.
        $config->set('authCode', null);
        $config->save();
        $logManager->appendLog('[configure] ERROR exchanging authorization code: ' . $e->getMessage());
        $logManager->appendLog('[configure] The code was cleared. Run the consent step again to get a fresh one.');
        return;
    }
}

// 3) Register the webhook if not registered yet.
if ($config->webhookId() === null) {
    $publicUrl = webhookUrl($logManager);
    if ($publicUrl === null) {
        return;
    }

    try {
        $accessToken = $tokenProvider->getAccessToken();
        $authedClient = new RevolutClient(new \GuzzleHttp\Client(), $config->environment(), $accessToken);
        $webhook = (new WebhooksApi($authedClient))->registerWebhook($publicUrl);

        $config->set('webhookId', (string) ($webhook['id'] ?? ''));
        if (isset($webhook['signing_secret']) && is_string($webhook['signing_secret'])) {
            $config->set('signingSecret', $webhook['signing_secret']);
        }
        $config->save();
        $logManager->appendLog('[configure] Webhook registered: ' . ($webhook['id'] ?? 'unknown') . ' -> ' . $publicUrl);
    } catch (\Throwable $e) {
        $logManager->appendLog('[configure] ERROR registering webhook: ' . $e->getMessage());
        return;
    }
}

$logManager->appendLog('[configure] Setup complete. Incoming Revolut payments will now be imported.');

// List the available accounts so the user can pick ids for "Revolut accounts to import from".
try {
    $accessToken = $tokenProvider->getAccessToken();
    $authedClient = new RevolutClient(new \GuzzleHttp\Client(), $config->environment(), $accessToken);
    $accounts = (new AccountsApi($authedClient))->listAccounts();

    $selected = $config->accountIds();
    $logManager->appendLog('[configure] Available Revolut accounts (paste the id(s) into "Revolut accounts to import from"; empty = all):');
    foreach ($accounts as $account) {
        $id = (string) ($account['id'] ?? '?');
        $mark = $selected === [] || in_array(strtolower($id), $selected, true) ? ' [importing]' : '';
        $logManager->appendLog(sprintf(
            '  - %s | %s | id: %s%s',
            (string) ($account['name'] ?? '?'),
            (string) ($account['currency'] ?? '?'),
            $id,
            $mark,
        ));
    }
} catch (\Throwable $e) {
    $logManager->appendLog('[configure] Could not list accounts: ' . $e->getMessage());
}

function consentUrl(PluginConfig $config): string
{
    $host = $config->isSandbox() ? 'https://sandbox-business.revolut.com' : 'https://business.revolut.com';

    return $host . '/app-confirm?' . http_build_query([
        'client_id' => $config->clientId(),
        'redirect_uri' => $config->redirectUri(),
        'response_type' => 'code',
        'scope' => 'READ,WRITE',
    ]);
}

function webhookUrl(PluginLogManager $logManager): ?string
{
    $options = UcrmOptionsManager::create()->loadOptions();
    $url = $options->pluginPublicUrl ?? null;
    if (! is_string($url) || $url === '') {
        $logManager->appendLog('[configure] ERROR: pluginPublicUrl is not set. Configure "Server domain name" in UCRM (HTTPS) so Revolut can reach the webhook.');

        return null;
    }
    if (! str_starts_with($url, 'https://')) {
        $logManager->appendLog('[configure] ERROR: webhook URL must be HTTPS. Current: ' . $url);

        return null;
    }

    return $url;
}
