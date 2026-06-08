<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Webhook\SignatureVerifier;

final class SignatureVerifierTest extends TestCase
{
    private const SECRET = 'wsk_testsecret';

    private function sign(string $timestamp, string $body): string
    {
        return 'v1=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $body, self::SECRET);
    }

    public function testAcceptsValidSignature(): void
    {
        $verifier = new SignatureVerifier(toleranceSeconds: 300);
        $body = '{"event":"TransactionCreated"}';
        $ts = '1700000000000'; // ms
        $now = 1_700_000_010;  // 10s later, in seconds

        self::assertTrue($verifier->isValid($body, $ts, $this->sign($ts, $body), self::SECRET, $now));
    }

    public function testRejectsTamperedBody(): void
    {
        $verifier = new SignatureVerifier(300);
        $ts = '1700000000000';
        $sig = $this->sign($ts, '{"a":1}');

        self::assertFalse($verifier->isValid('{"a":2}', $ts, $sig, self::SECRET, 1_700_000_010));
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $verifier = new SignatureVerifier(300);
        $body = '{}';
        $ts = '1700000000000';             // event at t=1_700_000_000s
        $now = 1_700_000_000 + 301;         // 301s later > 300 tolerance

        self::assertFalse($verifier->isValid($body, $ts, $this->sign($ts, $body), self::SECRET, $now));
    }

    public function testAcceptsWhenOneOfMultipleSignaturesMatches(): void
    {
        $verifier = new SignatureVerifier(300);
        $body = '{}';
        $ts = '1700000000000';
        $header = 'v1=deadbeef,' . $this->sign($ts, $body);

        self::assertTrue($verifier->isValid($body, $ts, $header, self::SECRET, 1_700_000_010));
    }

    public function testRejectsEmptyInputs(): void
    {
        $verifier = new SignatureVerifier(300);

        self::assertFalse($verifier->isValid('{}', '', 'v1=abc', self::SECRET, 1_700_000_010));
        self::assertFalse($verifier->isValid('{}', '1700000000000', '', self::SECRET, 1_700_000_010));
        self::assertFalse($verifier->isValid('', '1700000000000', 'v1=abc', self::SECRET, 1_700_000_010));
    }
}
