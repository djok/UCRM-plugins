<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Auth;

use RevolutPaymentsImport\Config\PluginConfig;
use RevolutPaymentsImport\Revolut\RevolutApiException;
use RevolutPaymentsImport\Revolut\RevolutClient;

/**
 * Manages the Revolut OAuth access token lifecycle.
 * - getAccessToken(): returns a cached token until it nears expiry, else refreshes.
 * - exchangeAuthorizationCode(): one-time swap of the consent code for tokens.
 * All token-endpoint calls authenticate with the JWT client assertion (no bearer).
 */
final class TokenProvider
{
    private const TOKEN_PATH = '/api/1.0/auth/token';
    private const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
    private const EXPIRY_SKEW_SECONDS = 120;
    private const ASSERTION_TTL_SECONDS = 120;

    public function __construct(
        private readonly RevolutClient $client,
        private readonly PluginConfig $config,
        private readonly JwtClientAssertion $assertion,
        private readonly string $privateKeyPem,
        private readonly int $now,
    ) {
    }

    public function getAccessToken(): string
    {
        $cached = $this->config->accessToken();
        $expiresAt = $this->config->accessTokenExpiresAt();
        if ($cached !== null && $expiresAt !== null && $expiresAt - self::EXPIRY_SKEW_SECONDS > $this->now) {
            return $cached;
        }

        $refreshToken = $this->config->refreshToken();
        if ($refreshToken === null) {
            throw new ReauthorizationRequiredException(
                'No refresh token stored — authorize the plugin: open the consent URL printed in the log.',
            );
        }

        try {
            $response = $this->client->postForm(self::TOKEN_PATH, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_assertion_type' => self::ASSERTION_TYPE,
                'client_assertion' => $this->buildAssertion(),
            ]);
        } catch (RevolutApiException $e) {
            // A 400/401 on the refresh grant means Revolut rejected the stored
            // refresh token — it will never recover on its own. Do NOT clear it
            // (a transient 401 must not force re-consent); tell the operator how.
            if ($e->statusCode === 400 || $e->statusCode === 401) {
                throw new ReauthorizationRequiredException(
                    'Revolut rejected the stored refresh token (HTTP ' . $e->statusCode . '). Re-authorize: '
                    . 'clear the "Refresh token (managed)" field in the plugin settings, Save, then open the '
                    . 'consent URL printed in the log.',
                    $e->statusCode,
                    $e,
                );
            }

            throw $e;
        }

        return $this->persistTokens($response)['access_token'];
    }

    public function exchangeAuthorizationCode(string $code): void
    {
        $response = $this->client->postForm(self::TOKEN_PATH, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_assertion_type' => self::ASSERTION_TYPE,
            'client_assertion' => $this->buildAssertion(),
        ]);

        $this->persistTokens($response);
    }

    private function buildAssertion(): string
    {
        $clientId = $this->config->clientId();
        $redirectUri = $this->config->redirectUri();
        if ($clientId === null || $redirectUri === null) {
            throw new \RuntimeException('clientId and redirectUri must be configured before authenticating.');
        }

        return $this->assertion->build(
            $clientId,
            $redirectUri,
            $this->now,
            self::ASSERTION_TTL_SECONDS,
            $this->privateKeyPem,
        );
    }

    /**
     * @param array<mixed> $response
     * @return array{access_token:string}
     */
    private function persistTokens(array $response): array
    {
        $accessToken = $response['access_token'] ?? null;
        if (! is_string($accessToken) || $accessToken === '') {
            $error = is_array($response) ? json_encode($response) : 'unknown';
            throw new \RuntimeException('Token endpoint did not return an access_token: ' . $error);
        }

        $expiresIn = isset($response['expires_in']) ? (int) $response['expires_in'] : 2400;
        $this->config->set('accessToken', $accessToken);
        $this->config->set('accessTokenExpiresAt', (string) ($this->now + $expiresIn));

        if (isset($response['refresh_token']) && is_string($response['refresh_token'])) {
            $this->config->set('refreshToken', $response['refresh_token']);
        }
        $this->config->save();

        return ['access_token' => $accessToken];
    }
}
