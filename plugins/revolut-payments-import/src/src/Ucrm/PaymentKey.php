<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

/**
 * The stable key linking a UISP payment to the Revolut transaction it was
 * created from. UISP 4.5.33 accepts providerName/providerPaymentId on
 * POST payments but does not persist them (they read back null), so the key
 * travels as the LAST segment of the payment note: "… | tx:<transaction id>".
 * The note is a persisted text column and keeps the "Revolut: " prefix, so
 * nothing that reads the note's beginning is affected.
 */
final class PaymentKey
{
    private const PREFIX = 'tx:';
    private const NOTE_PREFIX = 'Revolut:';

    /** Revolut transaction ids — API and statement CSV alike — are UUID-shaped. */
    private const ID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * The token must be the whole final segment, preceded by "|", the literal
     * "Revolut:" prefix, or the note start. Any other ':' is NOT a boundary, and
     * only a UUID counts — so a legacy note whose bank reference happens to end in
     * "Ref: tx:12345" is never mistaken for a keyed payment.
     */
    private const TOKEN_PATTERN = '/(?:^(?:Revolut:)?|\|)\s*tx:(' . self::ID . ')\s*$/u';

    /** True when the id can be written as a key and read back. */
    public static function isKey(string $transactionId): bool
    {
        return preg_match('/^' . self::ID . '$/', $transactionId) === 1;
    }

    public static function appendTo(string $note, string $transactionId): string
    {
        // An id that could not be read back is not written: the payment then
        // falls back to the legacy heuristic instead of carrying a dead key.
        if (! self::isKey($transactionId) || self::extract($note) === $transactionId) {
            return $note;
        }
        $token = self::PREFIX . $transactionId;
        $base = rtrim($note);
        if ($base === '') {
            return $token;
        }

        // "Revolut: " (no sender, reference or IBAN known) becomes "Revolut: tx:<id>".
        return $base === self::NOTE_PREFIX ? $base . ' ' . $token : $base . ' | ' . $token;
    }

    /** The transaction id from a note written by appendTo(), or null. */
    public static function extract(?string $note): ?string
    {
        if ($note === null || $note === '') {
            return null;
        }

        return preg_match(self::TOKEN_PATTERN, $note, $match) === 1 ? $match[1] : null;
    }

    /**
     * The Revolut transaction a UISP payment belongs to: the note key first, then
     * the provider fields in case a UISP version persists them. Null for legacy
     * payments created before the key existed (callers fall back to a heuristic).
     *
     * @param array<mixed> $payment UISP payment as returned by the API
     */
    public static function transactionIdOf(array $payment): ?string
    {
        $fromNote = self::extract(is_string($payment['note'] ?? null) ? $payment['note'] : null);
        if ($fromNote !== null) {
            return $fromNote;
        }

        $providerId = (string) ($payment['providerPaymentId'] ?? '');
        if (($payment['providerName'] ?? null) === UcrmPaymentGateway::PROVIDER_NAME && $providerId !== '') {
            return $providerId;
        }

        return null;
    }
}
