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

// Admin-only diagnostic: dumps the exact JSON the Revolut API returns for one
// transaction (?raw=1&tx=<id>), incl. every counterparty lookup — to inspect
// whether/where sender account data is exposed.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['raw'])) {
    handleRawPage($config, $logger);

    return;
}

// Admin-only status page: monthly reconciliation overview — every incoming
// Revolut transfer of the selected month with its UISP payment status. It is
// the default GET page so the UISP menu item (manifest "menu", iframe target)
// can open public.php without query parameters; webhooks arrive as POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
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
    $crmUrl = '';
    try {
        $crmUrl = rtrim((string) (UcrmOptionsManager::create()->loadOptions()->ucrmPublicUrl ?? ''), '/');
    } catch (\Throwable $e) {
        $crmUrl = '';
    }

    $user = null;
    try {
        $user = UcrmSecurity::create()->getUser();
    } catch (\Throwable $e) {
        $user = null;
    }
    if ($user === null || $user->isClient) {
        http_response_code(403);
        renderUispPage('Забранен достъп', '<p>Влезте в UISP като администратор и презаредете страницата.</p>', $crmUrl);

        return;
    }

    if ($config->refreshToken() === null) {
        renderUispPage('Не е свързано', '<p>Първо завършете оторизацията към Revolut (вижте лога на плъгина), после презаредете страницата.</p>', $crmUrl);

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

        renderUispPage(
            'Revolut преводи — ' . monthLabelBg($window->ym),
            renderStatusBody($rows, $summary, $window, $clientNames, $crmUrl),
            $crmUrl,
        );
    } catch (\Throwable $e) {
        // Never reflect internals on a public endpoint.
        $logger->error('Status page error: ' . $e->getMessage());
        http_response_code(500);
        renderUispPage('Грешка', '<p>Справката не можа да бъде заредена. Проверете лога на плъгина в UISP.</p>', $crmUrl);
    }
}

/**
 * All UISP payments created within [$fromDate, $toDate], padded by one day on
 * each side: the month window is UTC while UCRM filters createdDate in the
 * server's local timezone, so a transfer completed near UTC midnight carries a
 * payment dated in the neighboring day. Padding cannot create false matches —
 * exact matching is by provider id and the heuristic requires date equality.
 *
 * @return list<array<mixed>>
 */
function fetchMonthPayments(UcrmClient $ucrm, string $fromDate, string $toDate): array
{
    $paddedFrom = (new \DateTimeImmutable($fromDate . 'T00:00:00Z'))->modify('-1 day')->format('Y-m-d');
    $paddedTo = (new \DateTimeImmutable($toDate . 'T00:00:00Z'))->modify('+1 day')->format('Y-m-d');

    $all = [];
    for ($offset = 0; $offset < 100000; $offset += 500) {
        $page = array_values(array_filter($ucrm->get('payments', [
            'createdDateFrom' => $paddedFrom,
            'createdDateTo' => $paddedTo,
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
        StatusRow::STATUS_ASSIGNED => ['✅ Разнесен', 'success'],
        StatusRow::STATUS_UNASSIGNED => ['⚠️ Записан без клиент', 'warning'],
        StatusRow::STATUS_SKIPPED => ['⏭ Пропуснат (ръчно плащане)', 'secondary'],
        StatusRow::STATUS_MISSING => ['❌ Липсва', 'danger'],
    ];

    $options = '';
    foreach (MonthWindow::lastMonths(12, time()) as $ym) {
        $selected = $ym === $window->ym ? ' selected' : '';
        $options .= '<option value="' . htmlspecialchars($ym) . '"' . $selected . '>'
            . htmlspecialchars(monthLabelBg($ym)) . '</option>';
    }
    $previousYm = MonthWindow::lastMonths(2, time())[1];
    $html = '<div class="card mb-3"><div class="card-body py-2">'
        . '<form method="get" class="form-inline">'
        . '<input type="hidden" name="status" value="1">'
        . '<label class="mr-2 mb-0" for="frm-month"><small>Месец:</small></label>'
        . '<select name="month" id="frm-month" class="form-control form-control-sm mr-2">' . $options . '</select>'
        . '<button type="submit" class="btn btn-primary btn-sm">Покажи</button>'
        . '<span class="ml-3"><a href="?status=1">Текущ месец</a> · '
        . '<a href="?status=1&amp;month=' . htmlspecialchars($previousYm) . '">Предходен месец</a></span>'
        . '</form></div></div>';

    $html .= '<p class="mb-3">';
    foreach ($statusMeta as $status => [$label, $badge]) {
        $amounts = [];
        foreach ($summary[$status]['amounts'] as $currency => $sum) {
            $amounts[] = number_format($sum, 2, '.', ' ') . ' ' . $currency;
        }
        $html .= '<span class="badge badge-' . $badge . ' mr-2 mb-1">'
            . htmlspecialchars($label) . ': <strong>' . $summary[$status]['count'] . '</strong>'
            . ($amounts !== [] ? ' (' . htmlspecialchars(implode(', ', $amounts)) . ')' : '')
            . '</span>';
    }
    $html .= '</p>';

    if ($rows === []) {
        return $html . '<div class="card"><div class="card-body">Няма входящи Revolut преводи за избрания месец.</div></div>';
    }

    $cells = '';
    foreach ($rows as $row) {
        [$label, $badge] = $statusMeta[$row->status];
        $client = '&mdash;';
        if ($row->clientId !== null) {
            $name = htmlspecialchars($clientNames[$row->clientId] ?? ('#' . $row->clientId));
            // target=_top: open the client in the main window, not inside the menu iframe.
            $client = $crmUrl !== ''
                ? '<a href="' . htmlspecialchars($crmUrl . '/client/' . $row->clientId) . '" target="_top">' . $name . '</a>'
                : $name;
        }
        $cells .= '<tr class="table-' . $badge . '">'
            . '<td><a href="?raw=1&amp;tx=' . htmlspecialchars(rawurlencode($row->transactionId)) . '" title="Виж raw JSON от Revolut API">'
            . htmlspecialchars($row->date) . '</a></td>'
            . '<td class="text-right">' . number_format($row->amount, 2, '.', ' ') . '</td>'
            . '<td>' . htmlspecialchars($row->currency) . '</td>'
            . '<td>' . htmlspecialchars($row->sender)
            . ($row->clientId === null && $row->sender !== ''
                ? ' <a href="#" class="copy-sender" data-sender="' . htmlspecialchars($row->sender)
                    . '" title="Копирай името — добавете го като Bank account на клиента в UISP и всички бъдещи преводи от този изпращач ще се разнасят автоматично">⧉</a>'
                : '')
            . '</td>'
            . '<td>' . htmlspecialchars($row->reference) . '</td>'
            . '<td>' . htmlspecialchars($label) . '</td>'
            . '<td>' . $client . '</td>'
            . '</tr>';
    }
    $html .= '<div class="card"><div class="card-body p-0"><table class="table table-sm table-hover mb-0">'
        . '<thead class="thead-light"><tr><th>Дата</th><th class="text-right">Сума</th><th>Валута</th>'
        . '<th>Подател</th><th>Основание</th><th>Статус</th><th>Клиент</th></tr></thead>'
        . '<tbody>' . $cells . '</tbody>'
        . '</table></div></div>';

    $html .= '<script>document.addEventListener("click",function(e){'
        . 'var a=e.target.closest("a.copy-sender");if(!a)return;e.preventDefault();'
        . 'navigator.clipboard.writeText(a.dataset.sender).then(function(){a.textContent="✓";setTimeout(function(){a.textContent="⧉";},1500);})'
        . '.catch(function(){window.prompt("Копирай името:",a.dataset.sender);});'
        . '});</script>';

    return $html;
}

/**
 * Diagnostic page (admin-only): shows the exact JSON the Revolut API returns
 * for one transaction — the transaction object and every counterparty lookup
 * its legs reference — so sender-data coverage can be inspected and shared.
 */
function handleRawPage(PluginConfig $config, Logger $logger): void
{
    $crmUrl = '';
    try {
        $crmUrl = rtrim((string) (UcrmOptionsManager::create()->loadOptions()->ucrmPublicUrl ?? ''), '/');
    } catch (\Throwable $e) {
        $crmUrl = '';
    }

    $user = null;
    try {
        $user = UcrmSecurity::create()->getUser();
    } catch (\Throwable $e) {
        $user = null;
    }
    if ($user === null || $user->isClient) {
        http_response_code(403);
        renderUispPage('Забранен достъп', '<p>Влезте в UISP като администратор и презаредете страницата.</p>', $crmUrl);

        return;
    }

    if ($config->refreshToken() === null) {
        renderUispPage('Не е свързано', '<p>Първо завършете оторизацията към Revolut (вижте лога на плъгина).</p>', $crmUrl);

        return;
    }

    $txId = isset($_GET['tx']) && is_string($_GET['tx']) ? trim($_GET['tx']) : '';

    $form = '<div class="card mb-3"><div class="card-body py-2">'
        . '<form method="get" class="form-inline">'
        . '<input type="hidden" name="raw" value="1">'
        . '<label class="mr-2 mb-0" for="frm-tx"><small>Transaction id:</small></label>'
        . '<input type="text" name="tx" id="frm-tx" class="form-control form-control-sm mr-2" size="40" value="' . htmlspecialchars($txId) . '">'
        . '<button type="submit" class="btn btn-primary btn-sm">Покажи raw</button>'
        . '<span class="ml-3"><a href="?status=1">Към статус справката</a></span>'
        . '</form></div></div>';

    if ($txId === '') {
        renderUispPage(
            'Revolut raw транзакция',
            $form . '<p>Въведете transaction id — или отворете тази страница от линка върху датата в статус справката.</p>',
            $crmUrl,
        );

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

        $sections = '';
        $transaction = [];
        try {
            $transaction = $revolut->getJson('/api/1.0/transaction/' . rawurlencode($txId));
            $sections .= rawJsonSection('GET /api/1.0/transaction/' . $txId, $transaction);
        } catch (\Throwable $e) {
            $sections .= rawErrorSection('GET /api/1.0/transaction/' . $txId, $e);
        }

        // Every counterparty the legs reference — where the sender IBAN would
        // live if Revolut exposed it for this transfer.
        $legs = is_array($transaction['legs'] ?? null) ? $transaction['legs'] : [];
        $seenCounterparties = [];
        foreach ($legs as $leg) {
            $counterpartyId = is_array($leg) ? ($leg['counterparty']['id'] ?? null) : null;
            if (! is_string($counterpartyId) || $counterpartyId === '' || isset($seenCounterparties[$counterpartyId])) {
                continue;
            }
            $seenCounterparties[$counterpartyId] = true;
            try {
                $counterparty = $revolut->getJson('/api/1.0/counterparty/' . rawurlencode($counterpartyId));
                $sections .= rawJsonSection('GET /api/1.0/counterparty/' . $counterpartyId, $counterparty);
            } catch (\Throwable $e) {
                $sections .= rawErrorSection('GET /api/1.0/counterparty/' . $counterpartyId, $e);
            }
        }
        if ($legs !== [] && $seenCounterparties === []) {
            $sections .= '<p class="text-muted">Нито един leg няма counterparty — за този превод Revolut не предоставя обект със сметката на подателя.</p>';
        }

        renderUispPage('Revolut raw — ' . $txId, $form . $sections, $crmUrl);
    } catch (\Throwable $e) {
        $logger->error('Raw page error: ' . $e->getMessage());
        http_response_code(500);
        renderUispPage('Грешка', '<p>Заявката не можа да бъде изпълнена. Проверете лога на плъгина в UISP.</p>', $crmUrl);
    }
}

/** @param array<mixed> $payload decoded API response, re-rendered as pretty JSON */
function rawJsonSection(string $title, array $payload): string
{
    $json = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return '<div class="card mb-3"><div class="card-header py-2"><code>' . htmlspecialchars($title) . '</code></div>'
        . '<div class="card-body p-0"><textarea readonly class="form-control border-0" rows="'
        . min(30, substr_count($json, "\n") + 2)
        . '" style="font-family:monospace;font-size:.85rem">'
        . htmlspecialchars($json)
        . '</textarea></div></div>';
}

/** Admin-only diagnostic output — the API error is the information sought. */
function rawErrorSection(string $title, \Throwable $e): string
{
    return '<div class="card mb-3 border-danger"><div class="card-header py-2"><code>' . htmlspecialchars($title) . '</code></div>'
        . '<div class="card-body"><pre class="mb-0 text-danger" style="white-space:pre-wrap">' . htmlspecialchars($e->getMessage()) . '</pre></div></div>';
}

/**
 * UISP-look shell for the status page, mirroring the revenue-report plugin:
 * Lato from the UISP assets, Bootstrap 4 and the UISP header/background, so
 * the page blends in when opened in the admin UI iframe (manifest "menu").
 *
 * @param string $bodyHtml pre-escaped HTML
 */
function renderUispPage(string $title, string $bodyHtml, string $ucrmPublicUrl): void
{
    $latoCss = $ucrmPublicUrl !== ''
        ? '<link rel="stylesheet" href="' . htmlspecialchars($ucrmPublicUrl . '/assets/fonts/lato/lato.css') . '">'
        : '';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="bg"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . $latoCss
        . '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">'
        . '<style>'
        . '*{font-family:Lato,"Helvetica Neue",Helvetica,Arial,sans-serif}'
        . 'body{background-color:#edf0f3;-webkit-font-smoothing:antialiased}'
        . 'h1{margin:0;color:#4c4c4c;font-size:22px;line-height:1.2;font-weight:300}'
        . '#header{display:block;margin:0;background:#fff;box-shadow:0 0 1px 0 rgba(0,0,0,.1);padding:15px 32px}'
        . '#content{padding:18px 32px 32px}'
        . '.badge{font-size:.85rem;font-weight:400;padding:.4em .6em}'
        . '</style></head><body>'
        . '<div id="header"><h1>' . htmlspecialchars($title) . '</h1></div>'
        . '<div id="content">'
        . $bodyHtml
        . '</div></body></html>';
}

function renderHtml(string $title, string $message): void
{
    renderPage($title, '<p>' . htmlspecialchars($message) . '</p>');
}

/** @param string $bodyHtml pre-escaped HTML */
function renderPage(string $title, string $bodyHtml): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;line-height:1.5">'
        . '<h2>' . htmlspecialchars($title) . '</h2>'
        . $bodyHtml
        . '</body></html>';
}
