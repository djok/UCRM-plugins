<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

/**
 * Locates the UISP payment created for a Revolut transaction: exact match by
 * providerPaymentId (stamped since v1.6.0), else the legacy heuristic (note
 * prefix + amount + date). The query window is padded ±1 day because payment
 * createdDate filtering happens in the UISP server's local timezone.
 */
final class PaymentFinder
{
    private const AMOUNT_EPSILON = 0.005;
    private const LEGACY_NOTE_PREFIX = 'Revolut: ';

    public function __construct(private readonly UcrmClient $ucrm)
    {
    }

    /** @return array<mixed>|null */
    public function findForStatementRow(string $transactionId, string $dateYmd, float $amount): ?array
    {
        $from = (new \DateTimeImmutable($dateYmd . 'T00:00:00Z'))->modify('-1 day')->format('Y-m-d');
        $to = (new \DateTimeImmutable($dateYmd . 'T00:00:00Z'))->modify('+1 day')->format('Y-m-d');
        $payments = $this->ucrm->get('payments', [
            'createdDateFrom' => $from,
            'createdDateTo' => $to,
            'limit' => 500,
        ]);

        $legacyCandidate = null;
        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }
            if (
                ($payment['providerName'] ?? null) === UcrmPaymentGateway::PROVIDER_NAME
                && (string) ($payment['providerPaymentId'] ?? '') === $transactionId
            ) {
                return $payment;
            }
            if (
                $legacyCandidate === null
                && str_starts_with((string) ($payment['note'] ?? ''), self::LEGACY_NOTE_PREFIX)
                && abs((float) ($payment['amount'] ?? 0.0) - $amount) < self::AMOUNT_EPSILON
                && substr((string) ($payment['createdDate'] ?? ''), 0, 10) === $dateYmd
            ) {
                $legacyCandidate = $payment;
            }
        }

        return $legacyCandidate;
    }
}
