<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

interface PaymentRecorder
{
    public function record(IncomingPayment $payment): void;
}
