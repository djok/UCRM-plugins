<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

final class TransactionsApi implements TransactionSource
{
    public function __construct(private readonly RevolutClient $client)
    {
    }

    public function getTransaction(string $id): ?array
    {
        $tx = $this->client->getJson('/api/1.0/transaction/' . rawurlencode($id));

        return ($tx === [] || ! isset($tx['id'])) ? null : $tx;
    }

    /**
     * @return list<array<mixed>>
     */
    public function listTransactions(string $fromIso, string $toIso, int $count = 1000): array
    {
        $response = $this->client->getJson('/api/1.0/transactions', [
            'from' => $fromIso,
            'to' => $toIso,
            'count' => $count,
        ]);

        /** @var list<array<mixed>> $list */
        $list = array_values(array_filter($response, 'is_array'));

        return $list;
    }

    /**
     * Fetches the full window even when it exceeds Revolut's 1000-per-request cap,
     * by cursoring backwards on created_at (the API returns newest first). The
     * inclusive cursor may duplicate a boundary item — the idempotency store makes
     * that harmless downstream.
     *
     * @return list<array<mixed>>
     */
    public function listAllTransactions(string $fromIso, string $toIso, int $maxPages = 20): array
    {
        $all = [];
        $to = $toIso;
        for ($page = 0; $page < $maxPages; $page++) {
            $batch = $this->listTransactions($fromIso, $to, 1000);
            if ($batch === []) {
                break;
            }
            $all = array_merge($all, $batch);
            if (count($batch) < 1000) {
                break;
            }
            $oldest = $batch[count($batch) - 1]['created_at'] ?? null;
            if (! is_string($oldest) || $oldest === '' || $oldest === $to) {
                break;
            }
            $to = $oldest;
        }

        return $all;
    }
}
