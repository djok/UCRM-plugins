<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

final class AccountsApi
{
    public function __construct(private readonly RevolutClient $client)
    {
    }

    /**
     * @return list<array<mixed>> account objects (id, name, currency, state, ...)
     */
    public function listAccounts(): array
    {
        $response = $this->client->getJson('/api/1.0/accounts');

        /** @var list<array<mixed>> $list */
        $list = array_values(array_filter($response, 'is_array'));

        return $list;
    }
}
