<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

/**
 * Creates UCRM payments. Resolves the configured payment-method name to its id
 * once and caches it. Mirrors paysera-payments-import's generate_payment(),
 * adding currencyCode and an unassigned (no clientId) fallback.
 */
final class UcrmPaymentGateway implements PaymentRecorder
{
    private ?string $methodId = null;

    public function __construct(
        private readonly UcrmClient $ucrm,
        private readonly string $methodName,
    ) {
    }

    public function record(IncomingPayment $payment): void
    {
        $data = [
            'amount' => $payment->amount,
            'currencyCode' => $payment->currencyCode,
            'methodId' => $this->resolveMethodId(),
            'note' => $payment->note,
        ];
        if ($payment->clientId !== null) {
            $data['clientId'] = $payment->clientId;
        }

        $this->ucrm->post('payments', $data);
    }

    private function resolveMethodId(): string
    {
        if ($this->methodId !== null) {
            return $this->methodId;
        }

        foreach ($this->ucrm->get('payment-methods') as $method) {
            if (
                isset($method['name'], $method['id'])
                && mb_strtolower((string) $method['name']) === mb_strtolower($this->methodName)
            ) {
                return $this->methodId = (string) $method['id'];
            }
        }

        throw new \RuntimeException(sprintf("UCRM payment method '%s' not found.", $this->methodName));
    }
}
