<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Revolut;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Revolut\RevolutClient;
use RevolutPaymentsImport\Revolut\WebhooksApi;

final class WebhooksApiTest extends TestCase
{
    public function testRegisterWebhookReturnsIdAndSecret(): void
    {
        $mock = new MockHandler([
            new Response(201, [], (string) json_encode([
                'id' => 'wh-1',
                'url' => 'https://ucrm.example.com/_plugins/revolut-payments-import/public.php',
                'events' => ['TransactionCreated', 'TransactionStateChanged'],
                'signing_secret' => 'wsk_abc123',
            ])),
        ]);
        $client = new RevolutClient(new Client(['handler' => HandlerStack::create($mock)]), 'sandbox', 'tok');
        $api = new WebhooksApi($client);

        $result = $api->registerWebhook('https://ucrm.example.com/_plugins/revolut-payments-import/public.php');

        self::assertSame('wh-1', $result['id']);
        self::assertSame('wsk_abc123', $result['signing_secret']);
    }

    public function testFailedEventsReturnsPayloads(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                ['id' => 'fe-1', 'payload' => ['event' => 'TransactionCreated', 'data' => ['id' => 'tx-9']]],
            ])),
        ]);
        $client = new RevolutClient(new Client(['handler' => HandlerStack::create($mock)]), 'sandbox', 'tok');
        $api = new WebhooksApi($client);

        $events = $api->failedEvents('wh-1', 100);

        self::assertCount(1, $events);
        self::assertSame('tx-9', $events[0]['payload']['data']['id']);
    }
}
