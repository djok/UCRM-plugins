<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

use RevolutPaymentsImport\Support\Logger;

/**
 * Locates the UISP payment created for a Revolut transaction: exact match by
 * providerPaymentId (stamped since v1.6.0), else the legacy heuristic (note
 * prefix + amount + date), restricted to payments that are actually legacy
 * (no providerPaymentId of their own). The query window is padded ±1 day
 * because payment createdDate filtering happens in the UISP server's local
 * timezone. If more than one legacy candidate matches, the match is
 * ambiguous and null is returned rather than guessing.
 */
final class PaymentFinder implements PaymentFinderInterface
{
    private const AMOUNT_EPSILON = 0.005;
    private const LEGACY_NOTE_PREFIX = 'Revolut: ';
    private const PAGE_SIZE = 500;
    private const MAX_PAYMENTS_SCANNED = 20000;

    public function __construct(
        private readonly UcrmClient $ucrm,
        private readonly ?Logger $logger = null,
    ) {
    }

    /** @return array<mixed>|null */
    public function findForStatementRow(string $transactionId, string $dateYmd, float $amount): ?array
    {
        $from = (new \DateTimeImmutable($dateYmd . 'T00:00:00Z'))->modify('-1 day')->format('Y-m-d');
        $to = (new \DateTimeImmutable($dateYmd . 'T00:00:00Z'))->modify('+1 day')->format('Y-m-d');
        $payments = [];
        for ($offset = 0; $offset < self::MAX_PAYMENTS_SCANNED; $offset += self::PAGE_SIZE) {
            $page = array_values(array_filter($this->ucrm->get('payments', [
                'createdDateFrom' => $from,
                'createdDateTo' => $to,
                'limit' => self::PAGE_SIZE,
                'offset' => $offset,
            ]), 'is_array'));
            $payments = array_merge($payments, $page);
            if (count($page) < self::PAGE_SIZE) {
                break;
            }
        }

        $legacyCandidates = [];
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
                (string) ($payment['providerPaymentId'] ?? '') === ''
                && str_starts_with((string) ($payment['note'] ?? ''), self::LEGACY_NOTE_PREFIX)
                && abs((float) ($payment['amount'] ?? 0.0) - $amount) < self::AMOUNT_EPSILON
                && substr((string) ($payment['createdDate'] ?? ''), 0, 10) === $dateYmd
            ) {
                $legacyCandidates[] = $payment;
            }
        }

        if (count($legacyCandidates) > 1) {
            $this->logger?->info(sprintf(
                'PaymentFinder: %s — multiple legacy candidates on %s for %.2f; skipping (ambiguous).',
                $transactionId,
                $dateYmd,
                $amount,
            ));

            return null;
        }

        return $legacyCandidates[0] ?? null;
    }
}
