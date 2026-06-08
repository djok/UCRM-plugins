<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ubnt\UcrmPluginSdk\Service\PluginLogManager;

$log = PluginLogManager::create();
$keysDir = __DIR__ . '/data/keys';
$privatePath = $keysDir . '/private.pem';
$publicPath = $keysDir . '/public.cer';

if (! is_dir($keysDir)) {
    mkdir($keysDir, 0770, true);
}

// Deny web access to the keys directory.
file_put_contents($keysDir . '/.htaccess', "Require all denied\nDeny from all\n");

if (is_file($privatePath) && is_file($publicPath)) {
    $log->appendLog('[install] Keys already present — skipping generation.');
    return;
}

$config = [
    'digest_alg' => 'sha256',
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];

$privateKey = openssl_pkey_new($config);
if ($privateKey === false) {
    $log->appendLog('[install] ERROR: failed to generate RSA key: ' . openssl_error_string());
    return;
}

// Self-signed certificate (commonName is not significant to Revolut; the public key is).
$dn = ['commonName' => 'ucrm-revolut-plugin'];
$csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
$x509 = openssl_csr_sign($csr, null, $privateKey, 1825, ['digest_alg' => 'sha256']);

openssl_pkey_export($privateKey, $privatePem);
openssl_x509_export($x509, $certPem);

file_put_contents($privatePath, $privatePem);
chmod($privatePath, 0600);
file_put_contents($publicPath, $certPem);

$log->appendLog('[install] Generated RSA keypair and self-signed certificate.');
$log->appendLog('==================== REVOLUT SETUP — STEP 1 ====================');
$log->appendLog('1) In Revolut Business: Settings (gear) > APIs > Business API > add an API certificate.');
$log->appendLog('2) Paste the X509 public key below (including BEGIN/END lines).');
$log->appendLog('3) Set OAuth redirect URI to your UCRM URL (e.g. https://your-ucrm.example.com).');
$log->appendLog('4) Copy the issued ClientID.');
$log->appendLog('5) Open this plugin\'s Configuration, set Environment, ClientID and the same redirect URI, then Save.');
$log->appendLog('----- BEGIN PUBLIC CERTIFICATE (upload this) -----');
$log->appendLog($certPem);
$log->appendLog('----- END PUBLIC CERTIFICATE -----');
