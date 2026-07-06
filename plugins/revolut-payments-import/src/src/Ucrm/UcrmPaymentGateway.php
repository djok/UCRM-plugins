<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

/**
 * Creates UCRM payments. Resolves the configured payment method (by name or id)
 * once and caches it. Mirrors paysera-payments-import's generate_payment(),
 * adding currencyCode and an unassigned (no clientId) fallback.
 */
final class UcrmPaymentGateway implements PaymentRecorder
{
    /** Stamped on every created payment; the status page matches on it. */
    public const PROVIDER_NAME = 'Revolut';

    private ?string $methodId = null;

    public function __construct(
        private readonly UcrmClient $ucrm,
        private readonly string $methodNameOrId,
    ) {
    }

    public function record(IncomingPayment $payment): void
    {
        $data = [
            'amount' => $payment->amount,
            'currencyCode' => $payment->currencyCode,
            'methodId' => $this->resolveMethodId(),
            'note' => $payment->note,
            'providerName' => self::PROVIDER_NAME,
            'providerPaymentId' => $payment->externalId,
        ];
        if ($payment->clientId !== null) {
            $data['clientId'] = $payment->clientId;
        }
        if ($payment->createdDate !== null) {
            $data['createdDate'] = $payment->createdDate;
        }

        $this->ucrm->post('payments', $data);
    }

    private function resolveMethodId(): string
    {
        if ($this->methodId !== null) {
            return $this->methodId;
        }

        $wanted = mb_strtolower(trim($this->methodNameOrId));
        foreach ($this->ucrm->get('payment-methods') as $method) {
            if (! isset($method['id'])) {
                continue;
            }
            $id = (string) $method['id'];
            $name = isset($method['name']) ? mb_strtolower((string) $method['name']) : null;
            if ($name === $wanted || mb_strtolower($id) === $wanted) {
                return $this->methodId = $id;
            }
        }

        throw new \RuntimeException(sprintf("UCRM payment method '%s' not found.", $this->methodNameOrId));
    }
}
