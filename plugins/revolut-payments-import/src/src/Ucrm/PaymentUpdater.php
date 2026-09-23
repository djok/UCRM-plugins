<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

use RevolutPaymentsImport\Support\Logger;

/**
 * Attaches an existing unassigned UISP payment to a client. Kept separate
 * from creation (UcrmPaymentGateway) and intentionally non-throwing — callers
 * log and move on.
 *
 * Uses PATCH payments/{id}/attach: UISP rejects clientId on PATCH payments/{id}
 * ("This field is not allowed", 422). The attach applies the payment to the
 * client's unpaid invoices and turns any remainder into credit; it is reversible
 * via PATCH payments/{id}/detach. No receipt is e-mailed on a background re-match.
 */
final class PaymentUpdater implements PaymentUpdaterInterface
{
    public function __construct(
        private readonly UcrmClient $ucrm,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function attachClient(int $paymentId, int $clientId): bool
    {
        try {
            $this->ucrm->patch('payments/' . $paymentId . '/attach', ['clientId' => $clientId, 'sendReceipt' => false]);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('PaymentUpdater: PATCH payments/' . $paymentId . '/attach failed: ' . $e->getMessage());

            return false;
        }
    }
}
