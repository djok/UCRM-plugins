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
    /**
     * Stamped on every created payment. UISP 4.5.33 does not persist the provider
     * fields, so the matching key is the note token (see PaymentKey); they are
     * still sent in case a UISP version keeps them.
     */
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
            'note' => PaymentKey::appendTo($payment->note, $payment->externalId),
            'providerName' => self::PROVIDER_NAME,
            'providerPaymentId' => $payment->externalId,
        ];
        if ($payment->clientId !== null) {
            $data['clientId'] = $payment->clientId;
        }
        if ($payment->createdDate !== null) {
            $createdDate = $this->normalizeCreatedDate($payment->createdDate);
            if ($createdDate !== null) {
                $data['createdDate'] = $createdDate;
            }
        }

        $this->ucrm->post('payments', $data);
    }

    /**
     * UISP rejects datetimes with fractional seconds (400 "Invalid datetime …,
     * expected Y-m-TH:i:sO"), and Revolut's completed_at carries microseconds.
     * Normalize to second precision in UTC; an unparseable value is dropped so
     * the payment is recorded dated today instead of failing the POST.
     */
    private function normalizeCreatedDate(string $value): ?string
    {
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return null;
        }
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
