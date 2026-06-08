<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

interface TransactionSource
{
    /** @return array<mixed>|null the transaction object, or null if not found */
    public function getTransaction(string $id): ?array;
}
