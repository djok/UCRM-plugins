<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Revolut;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Revolut\HttpClientFactory;

final class HttpClientFactoryTest extends TestCase
{
    public function testClientHasSaneTimeouts(): void
    {
        $client = HttpClientFactory::create();

        self::assertSame(HttpClientFactory::REQUEST_TIMEOUT, $client->getConfig('timeout'));
        self::assertSame(HttpClientFactory::CONNECT_TIMEOUT, $client->getConfig('connect_timeout'));
        self::assertNotSame(0, $client->getConfig('timeout'));
    }
}
