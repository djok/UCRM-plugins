<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Ucrm;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Ucrm\PaymentKey;

final class PaymentKeyTest extends TestCase
{
    private const TX = '055d7bd0-0015-e343-0b40-032d2bd81330';

    public function testAppendsTokenAsLastNoteSegment(): void
    {
        self::assertSame(
            'Revolut: ACME LTD | INV 7 | BG00TEST1 | tx:' . self::TX,
            PaymentKey::appendTo('Revolut: ACME LTD | INV 7 | BG00TEST1', self::TX),
        );
    }

    public function testAppendsToANoteWithoutSegments(): void
    {
        // EventProcessor builds "Revolut: " when no sender, reference or IBAN is known.
        self::assertSame('Revolut: tx:' . self::TX, PaymentKey::appendTo('Revolut: ', self::TX));
        self::assertSame('tx:' . self::TX, PaymentKey::appendTo('', self::TX));
    }

    public function testReferenceEndingInAColonIsStillJoinedWithAPipe(): void
    {
        // Only the literal "Revolut:" prefix may sit directly before the token.
        $note = PaymentKey::appendTo('Revolut: ACME | Invoice:', self::TX);

        self::assertSame('Revolut: ACME | Invoice: | tx:' . self::TX, $note);
        self::assertSame(self::TX, PaymentKey::extract($note));
    }

    public function testExtractRoundTrips(): void
    {
        foreach (['Revolut: ACME LTD | INV 7', 'Revolut: ', '', "Revolut: ACME | line1\r\nline2 "] as $note) {
            self::assertSame(self::TX, PaymentKey::extract(PaymentKey::appendTo($note, self::TX)));
        }
    }

    public function testAppendIsIdempotent(): void
    {
        $once = PaymentKey::appendTo('Revolut: ACME', self::TX);

        self::assertSame($once, PaymentKey::appendTo($once, self::TX));
    }

    public function testExtractReturnsNullForLegacyAndForeignNotes(): void
    {
        self::assertNull(PaymentKey::extract(null));
        self::assertNull(PaymentKey::extract('Revolut: ACME LTD | INV 7 | BG00TEST1'));
        self::assertNull(PaymentKey::extract('платено на каса'));
    }

    public function testTokenMustBeAWholeFinalSegment(): void
    {
        // A bank reference that merely contains "tx:" must not be read as our key.
        self::assertNull(PaymentKey::extract('Revolut: ACME LTD | pay tx:' . self::TX));
        self::assertNull(PaymentKey::extract('Revolut: ACME LTD | tx:' . self::TX . ' | BG00TEST1'));
        // A ':' inside a legacy reference is not a segment boundary.
        self::assertNull(PaymentKey::extract('Revolut: ACME | Ref: tx:' . self::TX));
        self::assertNull(PaymentKey::extract('Revolut: ACME | Payment:tx:' . self::TX));
    }

    public function testOnlyRevolutTransactionIdsAreKeys(): void
    {
        // Revolut ids (API and statement CSV alike) are UUID-shaped; anything else
        // in a legacy reference ("| tx:12345") must not be mistaken for a key.
        self::assertNull(PaymentKey::extract('Revolut: ACME | tx:12345'));
        // An id that could not be read back is not written: the note stays as-is
        // and the payment falls back to the legacy heuristic.
        self::assertSame('Revolut: ACME', PaymentKey::appendTo('Revolut: ACME', 'not a uuid'));
    }

    public function testTransactionIdOfPrefersNoteTokenThenProviderFields(): void
    {
        // UISP 4.5.33 does not persist providerName/providerPaymentId: they read back null.
        self::assertSame(self::TX, PaymentKey::transactionIdOf([
            'note' => 'Revolut: ACME LTD | tx:' . self::TX,
            'providerName' => null,
            'providerPaymentId' => null,
        ]));
        // Should a UISP version persist them, they still work.
        self::assertSame('tx-legacy', PaymentKey::transactionIdOf([
            'note' => 'Revolut: ACME LTD',
            'providerName' => 'Revolut',
            'providerPaymentId' => 'tx-legacy',
        ]));
        self::assertNull(PaymentKey::transactionIdOf(['note' => 'Revolut: ACME LTD', 'providerName' => null]));
        // A provider id stamped by some other payment provider is not ours.
        self::assertNull(PaymentKey::transactionIdOf(['note' => 'x', 'providerName' => 'Stripe', 'providerPaymentId' => 'pi_1']));
    }
}
