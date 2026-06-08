<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Auth;

use Firebase\JWT\JWT;

/**
 * Builds the RS256 "private_key_jwt" client assertion required by Revolut's
 * OAuth token endpoint. Claims per developer.revolut.com:
 *   iss = redirect URI domain (no scheme), sub = ClientID,
 *   aud = https://revolut.com (constant for sandbox & production), exp = unix ts.
 */
final class JwtClientAssertion
{
    public const AUDIENCE = 'https://revolut.com';

    public function build(
        string $clientId,
        string $redirectUri,
        int $now,
        int $ttlSeconds,
        string $privateKeyPem,
    ): string {
        $payload = [
            'iss' => self::domainOf($redirectUri),
            'sub' => $clientId,
            'aud' => self::AUDIENCE,
            'exp' => $now + $ttlSeconds,
        ];

        return JWT::encode($payload, $privateKeyPem, 'RS256');
    }

    private static function domainOf(string $redirectUri): string
    {
        $host = parse_url($redirectUri, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }
        // No scheme present (e.g. "example.org" or "example.org/x"): take first path segment.
        $stripped = preg_replace('#^.*://#', '', $redirectUri) ?? $redirectUri;

        return explode('/', $stripped)[0];
    }
}
