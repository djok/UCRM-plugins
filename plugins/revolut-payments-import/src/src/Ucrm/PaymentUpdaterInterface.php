<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

interface PaymentUpdaterInterface
{
    public function attachClient(int $paymentId, int $clientId): bool;
}
