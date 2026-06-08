<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Auth\JwtClientAssertion;
use RevolutPaymentsImport\Auth\TokenProvider;
use RevolutPaymentsImport\Config\PluginConfig;
use RevolutPaymentsImport\Revolut\RevolutClient;

final class TokenProviderTest extends TestCase
{
    private string $path;
    private string $privateKey = '';

    protected function setUp(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $this->privateKey);

        $this->path = sys_get_temp_dir() . '/revolut-tok-' . uniqid() . '.json';
        file_put_contents($this->path, json_encode([
            'environment' => 'sandbox',
            'clientId' => 'client-123',
            'redirectUri' => 'https://ucrm.example.com',
            'refreshToken' => 'oa_sand_refresh_existing',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function provider(MockHandler $mock, PluginConfig $config): TokenProvider
    {
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new RevolutClient($http, 'sandbox');

        return new TokenProvider($client, $config, new JwtClientAssertion(), $this->privateKey, now: 1_700_000_000);
    }

    public function testRefreshesWhenNoCachedAccessToken(): void
    {
        $config = PluginConfig::fromFile($this->path);
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'access_token' => 'oa_sand_new_access',
                'token_type' => 'bearer',
                'expires_in' => 2400,
            ])),
        ]);

        $token = $this->provider($mock, $config)->getAccessToken();

        self::assertSame('oa_sand_new_access', $token);
        $reloaded = PluginConfig::fromFile($this->path);
        self::assertSame('oa_sand_new_access', $reloaded->accessToken());
        self::assertSame(1_700_002_400, $reloaded->accessTokenExpiresAt());
    }

    public function testUsesCachedTokenWhenStillValid(): void
    {
        file_put_contents($this->path, json_encode([
            'environment' => 'sandbox',
            'clientId' => 'client-123',
            'redirectUri' => 'https://ucrm.example.com',
            'refreshToken' => 'oa_sand_refresh_existing',
            'accessToken' => 'oa_sand_cached',
            'accessTokenExpiresAt' => 1_700_003_000, // 3000s in the future vs now=1_700_000_000
        ]));
        $config = PluginConfig::fromFile($this->path);
        $mock = new MockHandler([]); // no HTTP calls expected

        $token = $this->provider($mock, $config)->getAccessToken();

        self::assertSame('oa_sand_cached', $token);
    }

    public function testExchangeAuthorizationCodePersistsRefreshToken(): void
    {
        $config = PluginConfig::fromFile($this->path);
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'access_token' => 'oa_sand_access_after_code',
                'token_type' => 'bearer',
                'expires_in' => 2400,
                'refresh_token' => 'oa_sand_refresh_after_code',
            ])),
        ]);

        $this->provider($mock, $config)->exchangeAuthorizationCode('the-code');

        $reloaded = PluginConfig::fromFile($this->path);
        self::assertSame('oa_sand_refresh_after_code', $reloaded->refreshToken());
        self::assertSame('oa_sand_access_after_code', $reloaded->accessToken());
    }
}
