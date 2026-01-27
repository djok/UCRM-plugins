<?php
/**
 * Plugin Name: Paysera Payments Import
 * Description: Обработва плащания от Paysera и генерира записи за клиенти.
 * Version: 1.0
 * Author: Rosen Velikov
 */

declare(strict_types=1);

use Ubnt\UcrmPluginSdk\Security\PermissionNames;
use Ubnt\UcrmPluginSdk\Service\UcrmApi;
use Ubnt\UcrmPluginSdk\Service\UcrmOptionsManager;
use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;
// use Ubnt\UcrmPluginSdk\Service\UcrmHttpRequest;

chdir(__DIR__);

require_once __DIR__ . '/vendor/autoload.php';

$configManager = \Ubnt\UcrmPluginSdk\Service\PluginConfigManager::create();
$config = $configManager->loadConfig();

$timestamp = date('Y-m-d H:i:s');
$log = \Ubnt\UcrmPluginSdk\Service\PluginLogManager::create();
// Retrieve API connection.
$api = UcrmApi::create();



// Логване на IP адреса на заявката
$ipAddress = $_SERVER['REMOTE_ADDR'];
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
}
$log->appendLog("[$timestamp] New Request from " . $ipAddress);

if ($ipAddress !== $config['sourceIp']) {
    $log->appendLog("[$timestamp] Unauthorized IP address");
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized IP address']);
    exit;
}

$requestBody = file_get_contents('php://input');
$data = json_decode($requestBody, true);
// $request = UcrmHttpRequest::createFromGlobals();
// $data = json_decode($request->get_body(), true);

// Проверка за валидност на JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    $log->appendLog("[$timestamp] Invalid JSON");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Декодиране на съобщението
// $log->appendLog("[$timestamp] requestBody " . $requestBody);
// $log->appendLog("[$timestamp] data " . json_encode($data));
$decoded_text = parseMimeFromBase64($data);
// $log->appendLog("[$timestamp] decoded_text " . json_encode($decoded_text));
$parsed_result = parse_decoded_text($decoded_text);
// $log->appendLog("[$timestamp] parsed_result " . json_encode($parsed_result));
// Намираме клиента по банкова сметка
$client = find_client_by_account($api, $parsed_result['issuer_bank_account_number']);
generate_payment($api, $client, $parsed_result);

// $log->appendLog("[$timestamp] paysera payment processing - Payment generated");

/**
 * Примерна функция, която приема масив $data и извлича текстово съдържание от MIME съобщение,
 * без да ползва mailparse, а разчита изцяло на ZBateson\MailMimeParser (pure-PHP решение).
 *
 * @param array $data
 * @return string
 */
function parseMimeFromBase64(array $data)
{
    $timestamp = date('Y-m-d H:i:s');
    $log = \Ubnt\UcrmPluginSdk\Service\PluginLogManager::create();

    $decoded_full_text = "";

    // Проверка дали имаме "base64" => true и "message"
    if (
        isset($data["base64"]) && $data["base64"] === true
        && isset($data["message"])
    ) {
        try {
            // a) Base64 → сурови байтове (MIME e-mail)
            $raw_mime_bytes = base64_decode($data["message"]);
            if ($raw_mime_bytes === false) {
                $log->appendLog("[$timestamp] Неуспешно декодиране от base64.");
                throw new Exception("Неуспешно декодиране от base64.");
            }

            // b) Парсваме MIME през MailMimeParser (pure PHP)
            $parser  = new MailMimeParser();
            $message = $parser->parse($raw_mime_bytes, false);

            // c) Проверяваме дали е multipart
            if ($message->isMultiPart()) {
                // Обхождаме всички части
                foreach ($message->getAllParts() as $part) {
                    // Търсим text/plain
                    // (Някои пъти може да искате да проверите и text/html, в зависимост от нуждите)
                    if (stripos($part->getContentType(), 'text/plain') !== false) {
                        // MailMimeParser сам декодира според transfer-encoding и charset
                        $decoded_full_text .= $part->getContent();
                    }
                }
            } else {
                // Ако не е multipart, директно вземаме съдържанието
                $decoded_full_text = $message->getContent();
            }

        } catch (Exception $e) {
            // Ако нещо се обърка, връщаме текст за грешка
            $decoded_full_text = "Грешка при декодиране на MIME: " . $e->getMessage();
        }
    }

    return $decoded_full_text;
}

/**
 * Тук парсим декодирания текст (на кирилица),
 * за да извлечем данните като масив:
 * [
 *   "amount" => 1.00,
 *   "currency" => "BGN",
 *   "issuer_name" => "ЕВО",
 *   "issuer_bank_account_number" => "EVP5610010009412",
 *   "memo" => "временна финасова помощ"
 * ]
 *
 * @param string $decoded_text
 * @return array
 */
function parse_decoded_text($decoded_text)
{
    // Дефинираме резултатен масив с празни/начални стойности:
    $result = [
        "amount" => null,
        "currency" => "",
        "issuer_name" => "",
        "issuer_bank_account_number" => "",
        "memo" => ""
    ];

    // 1) ПАРСВАМЕ СУМАТА И ВАЛУТАТА
    //    Стар формат (BG): "Плащане на стойност 1.00 BGN е наредено ..."
    //    Нов формат (LT): "... gautas 12.44 EUR pervedimas."
    if (preg_match('/Плащане на стойност\s+([\d\.]+)\s+([A-Z]+)/u', $decoded_text, $matches)
        || preg_match('/gautas\s+([\d\.,]+)\s+([A-Z]+)\s+pervedimas/u', $decoded_text, $matches)
    ) {
        $amount = floatval(str_replace(',', '.', $matches[1]));
        $result["amount"]   = $amount;
        $result["currency"] = $matches[2];
    }

    // 2) ПАРСВАМЕ НАРЕДИТЕЛ / MOKĖTOJAS
    //    Стар формат (BG): "Наредител: **ЕВО** (EVP5610010009412)."
    //    Нов формат (LT): "Mokėtojas: **ТРАВЪЛ КОНСУЛТИНГ ЕООД** (BG25UNCR70001522861305)."
    if (preg_match('/Наредител:\s*\*\*(.*?)\*\*\s*\((.*?)\)/u', $decoded_text, $matches)
        || preg_match('/Mokėtojas:\s*\*\*(.*?)\*\*\s*\((.*?)\)/u', $decoded_text, $matches)
    ) {
        $result["issuer_name"]                = $matches[1];
        $result["issuer_bank_account_number"] = $matches[2];
    }

    // 3) ПАРСВАМЕ ОСНОВАНИЕ ЗА ПЛАЩАНЕ / MOKĖJIMO PASKIRTIS
    //    Стар формат (BG): "Основание за плащане: *временна финасова помощ*."
    //    Нов формат (LT): "Mokėjimo paskirtis: *Фактура 2601000519*."
    if (preg_match('/Основание за плащане:\s*\*(.*?)\*/u', $decoded_text, $matches)
        || preg_match('/Mokėjimo paskirtis:\s*\*(.*?)\*/u', $decoded_text, $matches)
    ) {
        $result["memo"] = $matches[1];
    }

    return $result;
}

// Намиране на клиент по банкова сметка
function find_client_by_account($api, $accountNumber)
{
    $accountNumberLower = mb_strtolower($accountNumber);
    $clients = $api->get('clients');

    foreach ($clients as $client) {
        if (! isset($client['bankAccounts']) || ! is_array($client['bankAccounts'])) {
            continue;
        }

        foreach ($client['bankAccounts'] as $bankAccount) {
            if (isset($bankAccount['accountNumber'])
                && mb_strtolower($bankAccount['accountNumber']) === $accountNumberLower
            ) {
                return $client;
            }
        }
    }

    return null;
}

// Генериране на плащане
function generate_payment($api, $client, $payment)
{
    $paymentData = [
        'amount' => $payment['amount'],
        'methodId' => '4145b5f5-3bbc-45e3-8fc5-9cda970c62fb', // Банков трансфер
        'note' => sprintf('%s (%s) %s', $payment['issuer_name'], $payment['memo'], $payment['issuer_bank_account_number']),
    ];

    if ($client && isset($client['id'])) {
        $paymentData['clientId'] = $client['id'];
    }

    $api->post('payments', $paymentData);
    
    return null;
}

?>