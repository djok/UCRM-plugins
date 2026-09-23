<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

use GuzzleHttp\Client;

/**
 * Builds the Guzzle client used for every Revolut call with sane timeouts, so a
 * hanging Revolut endpoint cannot pin a PHP-FPM worker (webhook/status page) or
 * stall the cron indefinitely. Guzzle's defaults are 0 (no timeout) and a 300 s
 * connect timeout — both unsafe for a synchronous request path.
 */
final class HttpClientFactory
{
    public const REQUEST_TIMEOUT = 30;
    public const CONNECT_TIMEOUT = 10;

    public static function create(): Client
    {
        return new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
        ]);
    }
}
