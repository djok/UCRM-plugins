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
}
