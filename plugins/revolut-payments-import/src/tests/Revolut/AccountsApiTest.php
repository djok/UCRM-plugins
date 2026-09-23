<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Revolut;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Revolut\AccountsApi;
use RevolutPaymentsImport\Revolut\RevolutClient;

final class AccountsApiTest extends TestCase
{
    public function testListAccountsReturnsAccountObjects(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                ['id' => 'acc-1', 'name' => 'Main', 'currency' => 'EUR', 'state' => 'active'],
                ['id' => 'acc-2', 'name' => 'Reserve', 'currency' => 'BGN', 'state' => 'active'],
            ])),
        ]);
        $client = new RevolutClient(new Client(['handler' => HandlerStack::create($mock)]), 'sandbox', 'tok');

        $accounts = (new AccountsApi($client))->listAccounts();

        self::assertCount(2, $accounts);
        self::assertSame('Main', $accounts[0]['name']);
        self::assertSame('acc-2', $accounts[1]['id']);
        // The account state must be passed through so callers can surface a
        // non-active (blocked/frozen) account to the operator.
        self::assertSame('active', $accounts[0]['state']);
    }

    public function testInactiveStateIsPreserved(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                ['id' => 'acc-1', 'name' => 'Main', 'currency' => 'EUR', 'state' => 'inactive'],
            ])),
        ]);
        $client = new RevolutClient(new Client(['handler' => HandlerStack::create($mock)]), 'sandbox', 'tok');

        $accounts = (new AccountsApi($client))->listAccounts();

        self::assertSame('inactive', $accounts[0]['state']);
    }
}
