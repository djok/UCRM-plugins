<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

final class CounterpartyApi implements CounterpartySource
{
    public function __construct(private readonly RevolutClient $client)
    {
    }

    public function getCounterparty(string $id): ?array
    {
        $cp = $this->client->getJson('/api/1.0/counterparty/' . rawurlencode($id));

        return ($cp === [] || ! isset($cp['id'])) ? null : $cp;
    }
}
