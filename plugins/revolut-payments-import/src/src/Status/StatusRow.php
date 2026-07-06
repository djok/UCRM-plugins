<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Status;

/**
 * One incoming Revolut transfer of the selected month with its UISP
 * reconciliation status. Immutable; rendered as a table row.
 */
final class StatusRow
{
    /** Payment exists in UISP and is attached to a client. */
    public const STATUS_ASSIGNED = 'assigned';
    /** Payment exists in UISP but has no client (waiting for manual attachment). */
    public const STATUS_UNASSIGNED = 'unassigned';
    /** No imported payment, but the id is in processed.json — the duplicate
     * guard recognized a manually entered payment. */
    public const STATUS_SKIPPED = 'skipped';
    /** The plugin never handled this transaction. */
    public const STATUS_MISSING = 'missing';

    public function __construct(
        public readonly string $transactionId,
        public readonly string $date,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $sender,
        public readonly string $reference,
        public readonly string $status,
        public readonly ?int $clientId = null,
    ) {
    }
}
