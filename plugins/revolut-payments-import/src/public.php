<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use Ubnt\UcrmPluginSdk\Service\PluginLogManager;
use Ubnt\UcrmPluginSdk\Service\UcrmOptionsManager;
use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;
use RevolutPaymentsImport\Auth\JwtClientAssertion;
use RevolutPaymentsImport\Auth\TokenProvider;
use RevolutPaymentsImport\Config\PluginConfig;
use RevolutPaymentsImport\Matching\ClientMatcher;
use RevolutPaymentsImport\Revolut\AccountsApi;
use RevolutPaymentsImport\Revolut\CounterpartyApi;
use RevolutPaymentsImport\Revolut\RevolutClient;
use RevolutPaymentsImport\Revolut\TransactionsApi;
use RevolutPaymentsImport\Revolut\WebhooksApi;
use RevolutPaymentsImport\Status\MonthlyStatusReport;
use RevolutPaymentsImport\Status\MonthWindow;
use RevolutPaymentsImport\Status\StatusRow;
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\SdkUcrmClient;
use RevolutPaymentsImport\Ucrm\UcrmClient;
use RevolutPaymentsImport\Ucrm\UcrmPaymentGateway;
use RevolutPaymentsImport\Webhook\EventProcessor;
use RevolutPaymentsImport\Webhook\SignatureVerifier;

chdir(__DIR__);

$logManager = PluginLogManager::create();
$logger = new Logger([$logManager, 'appendLog']);
$config = PluginConfig::fromFile(__DIR__ . '/data/config.json');

// OAuth callback: Revolut redirects the browser here with ?code=... after consent.
// Exchange the code for tokens and register the webhook automatically — no manual copy.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['code'])) {
    handleOAuthCallback($config, $logger);

    return;
}

// Admin-only helper page: lists the Revolut accounts so the admin can pick the
// id(s) for the "Revolut accounts to import from" setting.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['accounts'])) {
    handleAccountsPage($config, $logger);

    return;
}

// Admin-only status page: monthly reconciliation overview — every incoming
// Revolut transfer of the selected month with its UISP payment status.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['status'])) {
    handleStatusPage($config, $logger);

    return;
}

$rawBody = (string) file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_REVOLUT_REQUEST_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_REVOLUT_SIGNATURE'] ?? '';

$signingSecret = $config->signingSecret();
if ($signingSecret === null) {
    $logger->error('Webhook received but plugin is not configured (no signing secret).');
    http_response_code(503);
    echo json_encode(['error' => 'not configured']);

    return;
}

$verifier = new SignatureVerifier();
if (! $verifier->isValid($rawBody, (string) $timestamp, (string) $signature, $signingSecret, time())) {
    $logger->error('Webhook signature verification failed.');
    http_response_code(401);
    echo json_encode(['error' => 'invalid signature']);

    return;
}

$event = json_decode($rawBody, true);
if (! is_array($event)) {
    $logger->error('Webhook body is not valid JSON.');
    http_response_code(400);
    echo json_encode(['error' => 'invalid json']);

    return;
}

try {
    $processor = buildProcessor($config, $logger);
    $processor->processEvent($event);
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} catch (\Throwable $e) {
    // Acknowledge so Revolut does not hammer retries; reconciliation will recover.
    $logger->error('Error processing webhook: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['status' => 'deferred']);
}

function buildProcessor(PluginConfig $config, Logger $logger): EventProcessor
{
    $privateKey = (string) file_get_contents(__DIR__ . '/data/keys/private.pem');
    $tokenClient = new RevolutClient(new Client(), $config->environment());
    $tokenProvider = new TokenProvider($tokenClient, $config, new JwtClientAssertion(), $privateKey, time());
    $accessToken = $tokenProvider->getAccessToken();

    $revolut = new RevolutClient(new Client(), $config->environment(), $accessToken);
    $ucrm = SdkUcrmClient::create();

    return new EventProcessor(
        new TransactionsApi($revolut),
        new CounterpartyApi($revolut),
        new ClientMatcher($ucrm),
        new UcrmPaymentGateway($ucrm, (string) $config->paymentMethodName()),
        new IdempotencyStore(__DIR__ . '/data/processed.json'),
        $logger,
        $config->accountIds(),
    );
}

/**
 * OAuth callback handler. Revolut redirects the browser here (GET ?code=...) after
 * the user approves consent. We exchange the one-time code for tokens and register
 * the webhook, so the user never has to copy the code manually.
 */
function handleOAuthCallback(PluginConfig $config, Logger $logger): void
{
    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') {
        http_response_code(400);
        renderHtml('Setup failed', 'No authorization code was provided in the redirect.');

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

        // 1) Exchange the one-time code for tokens (persists refresh + access token).
        $tokenProvider->exchangeAuthorizationCode($code);
        $logger->info('OAuth callback: obtained tokens from authorization code.');

        // 2) Register the webhook if it is not registered yet.
        if ($config->webhookId() === null) {
            $webhookUrl = (string) (UcrmOptionsManager::create()->loadOptions()->pluginPublicUrl ?? '');
            if (! str_starts_with($webhookUrl, 'https://')) {
                throw new \RuntimeException(
                    'pluginPublicUrl is not an HTTPS URL — configure "Server domain name" in UCRM so Revolut can reach the webhook.'
                );
            }
            $accessToken = $tokenProvider->getAccessToken();
            $authedClient = new RevolutClient(new Client(), $config->environment(), $accessToken);
            $webhook = (new WebhooksApi($authedClient))->registerWebhook($webhookUrl);

            $config->set('webhookId', (string) ($webhook['id'] ?? ''));
            if (isset($webhook['signing_secret']) && is_string($webhook['signing_secret'])) {
                $config->set('signingSecret', $webhook['signing_secret']);
            }
            $config->save();
            $logger->info('OAuth callback: webhook registered (' . ($webhook['id'] ?? 'unknown') . ').');
        }

        http_response_code(200);
        renderHtml(
            'Revolut connected',
            'Setup complete. Incoming Revolut payments will now be imported into UCRM. You can close this window.',
        );
    } catch (\Throwable $e) {
        // public.php is reachable by anyone — never reflect internal error details here.
        $logger->error('OAuth callback error: ' . $e->getMessage());
        http_response_code(500);
        renderHtml('Setup failed', 'Could not complete setup. Check the plugin log in UCRM for details.');
    }
}

/**
 * Lists Revolut accounts to a logged-in UCRM admin (read-only). Anonymous or
 * client-zone visitors get 403 — the page must not leak account metadata.
 */
function handleAccountsPage(PluginConfig $config, Logger $logger): void
{
    $user = null;
    try {
        $user = UcrmSecurity::create()->getUser();
    } catch (\Throwable $e) {
        $user = null;
    }
    if ($user === null || $user->isClient) {
        http_response_code(403);
        renderHtml('Forbidden', 'Log in to UCRM as an administrator, then reload this page.');

        return;
    }

    if ($config->refreshToken() === null) {
        renderHtml('Not connected', 'Finish the Revolut authorization first (see the plugin log), then reload this page.');

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
        $accounts = (new AccountsApi($revolut))->listAccounts();

        $selected = $config->accountIds();
        $rows = '';
        foreach ($accounts as $account) {
            $id = (string) ($account['id'] ?? '');
            $importing = $selected === [] || in_array(strtolower($id), $selected, true);
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars((string) ($account['name'] ?? '?')) . '</td>'
                . '<td>' . htmlspecialchars((string) ($account['currency'] ?? '?')) . '</td>'
                . '<td><code>' . htmlspecialchars($id) . '</code></td>'
                . '<td>' . ($importing ? '&#10003; importing' : '&mdash;') . '</td>'
                . '</tr>';
        }
        $body = '<p>Paste the wanted account id(s), comma-separated, into the plugin setting '
            . '<strong>&quot;Revolut accounts to import from&quot;</strong> and Save. '
            . 'Leave the setting empty to import from all accounts.</p>'
            . '<table border="1" cellpadding="6" style="border-collapse:collapse">'
            . '<tr><th>Name</th><th>Currency</th><th>Account id</th><th>Status</th></tr>'
            . $rows
            . '</table>';
        renderPage('Revolut accounts', $body);
    } catch (\Throwable $e) {
        $logger->error('Accounts page error: ' . $e->getMessage());
        http_response_code(500);
        renderHtml('Error', 'Could not load the account list. Check the plugin log in UCRM for details.');
    }
}

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

function renderHtml(string $title, string $message): void
{
    renderPage($title, '<p>' . htmlspecialchars($message) . '</p>');
}

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
