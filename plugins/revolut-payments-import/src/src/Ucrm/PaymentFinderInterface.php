<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

interface PaymentFinderInterface
{
    /** @return array<mixed>|null */
    public function findForStatementRow(string $transactionId, string $dateYmd, float $amount): ?array;
}
