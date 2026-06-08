<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

/**
 * Revolut Webhooks v2 (/api/2.0/webhooks). The signing_secret is returned
 * ONLY by registerWebhook (and GET of a specific webhook) — persist it then.
 */
final class WebhooksApi
{
    private const BASE = '/api/2.0/webhooks';
    public const DEFAULT_EVENTS = ['TransactionCreated', 'TransactionStateChanged'];

    public function __construct(private readonly RevolutClient $client)
    {
    }

    /**
     * @param list<string> $events
     * @return array<mixed> includes 'id' and 'signing_secret'
     */
    public function registerWebhook(string $url, array $events = self::DEFAULT_EVENTS): array
    {
        return $this->client->postJson(self::BASE, [
            'url' => $url,
            'events' => $events,
        ]);
    }

    /**
     * @return list<array<mixed>> each item has 'id', 'payload', 'created_at', ...
     */
    public function failedEvents(string $webhookId, int $limit = 100): array
    {
        $response = $this->client->getJson(
            self::BASE . '/' . rawurlencode($webhookId) . '/failed-events',
            ['limit' => $limit],
        );

        /** @var list<array<mixed>> $list */
        $list = array_values(array_filter($response, 'is_array'));

        return $list;
    }
}
