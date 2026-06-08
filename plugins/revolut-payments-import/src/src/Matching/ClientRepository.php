<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Matching;

interface ClientRepository
{
    /** @return array<mixed>|null the matched UCRM client, or null */
    public function findClientByIban(string $iban): ?array;
}
