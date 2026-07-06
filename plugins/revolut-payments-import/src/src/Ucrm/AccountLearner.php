<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

interface AccountLearner
{
    /** @param array<int,string|null> $accountNumbers candidate identities (IBAN, sender name) */
    public function learn(int $clientId, array $accountNumbers): void;
}
