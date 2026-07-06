<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

use RevolutPaymentsImport\Support\Logger;

/**
 * Attaches an existing unassigned UISP payment to a client. Kept separate
 * from creation (UcrmPaymentGateway) and intentionally non-throwing: some
 * UISP versions may not allow PATCHing payments — callers log and move on.
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
            $this->ucrm->patch('payments/' . $paymentId, ['clientId' => $clientId]);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('PaymentUpdater: PATCH payments/' . $paymentId . ' failed: ' . $e->getMessage());

            return false;
        }
    }
}
