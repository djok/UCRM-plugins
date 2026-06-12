<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

/**
 * Immutable description of one incoming payment to record in UCRM.
 * externalId is the Revolut transaction id (used for traceability/idempotency).
 * createdDate (ISO 8601) carries the bank-side completion time so backfilled
 * payments keep their historical date instead of the import time.
 */
final class IncomingPayment
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currencyCode,
        public readonly ?int $clientId,
        public readonly string $note,
        public readonly string $externalId,
        public readonly ?string $createdDate = null,
    ) {
    }
}
