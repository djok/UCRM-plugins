<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

/**
 * UISP returns a payment's createdDate in the server's local offset (e.g.
 * "2026-06-15T01:30:00+0300"), while the plugin writes it as the transfer's
 * UTC instant and every Revolut date it compares against is UTC. Comparing the
 * leading "Y-m-d" of the local string misdates transfers completed near
 * midnight UTC.
 */
final class UispDate
{
    /** The UTC calendar date of a UISP createdDate; '' when missing or unparseable. */
    public static function utcDate(mixed $createdDate): string
    {
        if (! is_string($createdDate) || $createdDate === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($createdDate))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        } catch (\Exception $e) {
            return '';
        }
    }
}
