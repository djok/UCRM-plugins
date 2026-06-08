<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Auth\JwtClientAssertion;

final class JwtClientAssertionTest extends TestCase
{
    private string $privateKey = '';
    private string $publicKey = '';

    protected function setUp(): void
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($res);
        openssl_pkey_export($res, $this->privateKey);
        $details = openssl_pkey_get_details($res);
        $this->publicKey = $details['key'];
        // Fix JWT library's internal clock so tokens with past `exp` still decode.
        JWT::$timestamp = 1_700_000_000;
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
    }

    public function testBuildsJwtWithExpectedClaims(): void
    {
        $builder = new JwtClientAssertion();

        $jwt = $builder->build(
            clientId: 'client-123',
            redirectUri: 'https://ucrm.example.com/path',
            now: 1_700_000_000,
            ttlSeconds: 120,
            privateKeyPem: $this->privateKey,
        );

        $decoded = JWT::decode($jwt, new Key($this->publicKey, 'RS256'));

        self::assertSame('ucrm.example.com', $decoded->iss); // scheme + path stripped
        self::assertSame('client-123', $decoded->sub);
        self::assertSame('https://revolut.com', $decoded->aud);
        self::assertSame(1_700_000_120, $decoded->exp);
    }

    public function testIssDerivedFromBareDomain(): void
    {
        $builder = new JwtClientAssertion();

        $jwt = $builder->build('c', 'example.org', 1_700_000_000, 60, $this->privateKey);
        $decoded = JWT::decode($jwt, new Key($this->publicKey, 'RS256'));

        self::assertSame('example.org', $decoded->iss);
    }
}
