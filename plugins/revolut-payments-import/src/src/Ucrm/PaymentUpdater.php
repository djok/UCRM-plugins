<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

/**
 * Attaches an existing unassigned UISP payment to a client. Kept separate
 * from creation (UcrmPaymentGateway) and intentionally non-throwing: some
 * UISP versions may not allow PATCHing payments — callers log and move on.
 */
final class PaymentUpdater
{
    public function __construct(private readonly UcrmClient $ucrm)
    {
    }

    public function attachClient(int $paymentId, int $clientId): bool
    {
        try {
            $this->ucrm->patch('payments/' . $paymentId, ['clientId' => $clientId]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
