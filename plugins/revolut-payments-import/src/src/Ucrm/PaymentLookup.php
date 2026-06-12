<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

interface PaymentLookup
{
    /**
     * Whether the client already has a payment of this amount on the given day
     * (used to avoid duplicating manually entered payments during imports).
     */
    public function clientHasPaymentOn(int $clientId, string $dateYmd, float $amount): bool;
}
