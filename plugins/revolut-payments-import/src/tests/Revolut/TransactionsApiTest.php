<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Revolut;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Revolut\CounterpartyApi;
use RevolutPaymentsImport\Revolut\RevolutClient;
use RevolutPaymentsImport\Revolut\TransactionsApi;

final class TransactionsApiTest extends TestCase
{
    public function testGetTransactionReturnsObject(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'id' => 'tx-1',
                'type' => 'transfer',
                'state' => 'completed',
                'legs' => [['amount' => 12.44, 'currency' => 'EUR']],
            ])),
        ]);
        $client = new RevolutClient(new Client(['handler' => HandlerStack::create($mock)]), 'sandbox', 'tok');

        $tx = (new TransactionsApi($client))->getTransaction('tx-1');

        self::assertNotNull($tx);
        self::assertSame('completed', $tx['state']);
        self::assertSame(12.44, $tx['legs'][0]['amount']);
    }

    public function testListTransactionsPassesQuery(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([['id' => 'tx-1'], ['id' => 'tx-2']])),
        ]);
        $client = new RevolutClient(new Client(['handler' => HandlerStack::create($mock)]), 'sandbox', 'tok');

        $list = (new TransactionsApi($client))->listTransactions('2026-06-01T00:00:00Z', '2026-06-08T00:00:00Z');

        self::assertCount(2, $list);
    }

    public function testGetCounterpartyReturnsAccounts(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'id' => 'cp-1',
                'name' => 'John Doe',
                'accounts' => [['iban' => 'BG80BNBG96611020345678', 'currency' => 'BGN']],
            ])),
        ]);
        $client = new RevolutClient(new Client(['handler' => HandlerStack::create($mock)]), 'sandbox', 'tok');

        $cp = (new CounterpartyApi($client))->getCounterparty('cp-1');

        self::assertNotNull($cp);
        self::assertSame('John Doe', $cp['name']);
        self::assertSame('BG80BNBG96611020345678', $cp['accounts'][0]['iban']);
    }
}
