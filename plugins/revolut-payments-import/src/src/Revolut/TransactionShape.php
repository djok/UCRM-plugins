<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

/**
 * Structural checks on a Revolut transaction, shared by the importer and the
 * status page so both classify transfers identically.
 */
final class TransactionShape
{
    /**
     * True when the transaction moves money between the business's OWN accounts.
     * Revolut legs are always on our own accounts (the payer is a counterparty,
     * not a leg; fees are a leg field, not a leg). Per Revolut's spec a
     * transaction has 2 legs only when it is "between your Revolut accounts",
     * otherwise exactly 1 — so a customer transfer is a single positive leg,
     * while an internal move has a second leg, normally negative on the account
     * the money left: Revolut's hold/"Release" during an account seizure, pocket
     * moves, exchanges. Such a transfer is never a customer payment.
     *
     * @param array<mixed> $transaction
     */
    public static function isInternalTransfer(array $transaction): bool
    {
        $legs = $transaction['legs'] ?? [];
        if (! is_array($legs)) {
            return false;
        }
        if (count($legs) >= 2) {
            return true;
        }
        foreach ($legs as $leg) {
            if (is_array($leg) && isset($leg['amount']) && (float) $leg['amount'] < 0.0) {
                return true;
            }
        }

        return false;
    }
}
