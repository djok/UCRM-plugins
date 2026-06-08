<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

/**
 * Immutable description of one incoming payment to record in UCRM.
 * externalId is the Revolut transaction id (used for traceability/idempotency).
 */
final class IncomingPayment
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currencyCode,
        public readonly ?int $clientId,
        public readonly string $note,
        public readonly string $externalId,
    ) {
    }
}
