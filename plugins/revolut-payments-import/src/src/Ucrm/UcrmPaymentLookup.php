<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

final class UcrmPaymentLookup implements PaymentLookup
{
    private const AMOUNT_EPSILON = 0.005;

    public function __construct(private readonly UcrmClient $ucrm)
    {
    }

    public function clientHasPaymentOn(int $clientId, string $dateYmd, float $amount, string $transactionId): bool
    {
        $payments = $this->ucrm->get('payments', [
            'clientId' => $clientId,
            'createdDateFrom' => $dateYmd,
            'createdDateTo' => $dateYmd,
        ]);

        foreach ($payments as $payment) {
            if (
                ! is_array($payment)
                || ! isset($payment['amount'])
                || abs((float) $payment['amount'] - $amount) >= self::AMOUNT_EPSILON
            ) {
                continue;
            }
            // A plugin payment for a different transfer is not a duplicate of this
            // one — the client simply paid the same amount twice that day.
            $key = PaymentKey::transactionIdOf($payment);
            if ($key !== null && $key !== $transactionId) {
                continue;
            }

            return true;
        }

        return false;
    }
}
