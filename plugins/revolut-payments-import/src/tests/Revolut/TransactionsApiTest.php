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

    public function testListAllTransactionsPaginatesPastThePageLimit(): void
    {
        // Page 1: a full page of 1000 (newest first), oldest item cursor at 2026-03-01.
        $page1 = [];
        for ($i = 0; $i < 1000; $i++) {
            $page1[] = ['id' => 'tx-' . $i, 'created_at' => '2026-03-10T00:00:00Z'];
        }
        $page1[999]['created_at'] = '2026-03-01T00:00:00Z';
        // Page 2: a short page ends the loop.
        $page2 = [
            ['id' => 'tx-old-1', 'created_at' => '2026-02-20T00:00:00Z'],
            ['id' => 'tx-old-2', 'created_at' => '2026-02-10T00:00:00Z'],
            ['id' => 'tx-old-3', 'created_at' => '2026-02-01T00:00:00Z'],
        ];
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode($page1)),
            new Response(200, [], (string) json_encode($page2)),
        ]);
        $client = new RevolutClient(new Client(['handler' => HandlerStack::create($mock)]), 'sandbox', 'tok');

        $all = (new TransactionsApi($client))->listAllTransactions('2026-01-01T00:00:00Z', '2026-06-01T00:00:00Z');

        self::assertCount(1003, $all);
        self::assertSame(0, $mock->count()); // both pages were fetched
    }

    public function testListAllTransactionsSinglePageNeedsOneRequest(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([['id' => 'tx-1', 'created_at' => '2026-05-01T00:00:00Z']])),
        ]);
        $client = new RevolutClient(new Client(['handler' => HandlerStack::create($mock)]), 'sandbox', 'tok');

        $all = (new TransactionsApi($client))->listAllTransactions('2026-01-01T00:00:00Z', '2026-06-01T00:00:00Z');

        self::assertCount(1, $all);
        self::assertSame(0, $mock->count());
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
