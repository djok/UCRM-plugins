<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

interface CounterpartySource
{
    /** @return array<mixed>|null the counterparty object, or null if not found */
    public function getCounterparty(string $id): ?array;
}
