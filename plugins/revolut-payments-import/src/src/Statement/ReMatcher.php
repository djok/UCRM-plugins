<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Statement;

interface ReMatcher
{
    /**
     * @param array{id:string,date:string,amount:float,currency:string,reference:string,senderName:string,senderIban:string} $row
     * @return bool false when an attempted attach failed (worth retrying), true otherwise
     */
    public function reMatch(array $row): bool;
}
