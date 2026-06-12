<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

final class UcrmPaymentLookup implements PaymentLookup
{
    private const AMOUNT_EPSILON = 0.005;

    public function __construct(private readonly UcrmClient $ucrm)
    {
    }

    public function clientHasPaymentOn(int $clientId, string $dateYmd, float $amount): bool
    {
        $payments = $this->ucrm->get('payments', [
            'clientId' => $clientId,
            'createdDateFrom' => $dateYmd,
            'createdDateTo' => $dateYmd,
        ]);

        foreach ($payments as $payment) {
            if (
                is_array($payment)
                && isset($payment['amount'])
                && abs((float) $payment['amount'] - $amount) < self::AMOUNT_EPSILON
            ) {
                return true;
            }
        }

        return false;
    }
}
