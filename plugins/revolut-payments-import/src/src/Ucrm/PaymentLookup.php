<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

interface PaymentLookup
{
    /**
     * Whether importing $transactionId would duplicate a payment the client already
     * has: one of this amount on the given day that was entered manually or is this
     * very transfer's own payment. The plugin's payments for OTHER transfers never
     * count, so two genuine same-amount transfers on one day are both imported.
     */
    public function clientHasPaymentOn(int $clientId, string $dateYmd, float $amount, string $transactionId): bool;
}
