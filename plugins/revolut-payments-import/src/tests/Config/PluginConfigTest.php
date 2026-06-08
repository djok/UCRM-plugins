<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Config;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Config\PluginConfig;

final class PluginConfigTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/revolut-cfg-' . uniqid() . '.json';
        file_put_contents($this->path, json_encode([
            'environment' => 'sandbox',
            'clientId' => 'abc123',
            'redirectUri' => 'https://ucrm.example.com',
            'paymentMethodName' => 'Bank transfer',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testReadsScalarValues(): void
    {
        $config = PluginConfig::fromFile($this->path);

        self::assertSame('sandbox', $config->environment());
        self::assertSame('abc123', $config->clientId());
        self::assertSame('https://ucrm.example.com', $config->redirectUri());
        self::assertSame('Bank transfer', $config->paymentMethodName());
    }

    public function testMissingOptionalValuesAreNull(): void
    {
        $config = PluginConfig::fromFile($this->path);

        self::assertNull($config->refreshToken());
        self::assertNull($config->signingSecret());
        self::assertNull($config->webhookId());
    }

    public function testSetPersistsToDisk(): void
    {
        $config = PluginConfig::fromFile($this->path);
        $config->set('refreshToken', 'oa_prod_refresh');
        $config->set('signingSecret', 'wsk_secret');
        $config->save();

        $reloaded = PluginConfig::fromFile($this->path);
        self::assertSame('oa_prod_refresh', $reloaded->refreshToken());
        self::assertSame('wsk_secret', $reloaded->signingSecret());
        // existing keys preserved
        self::assertSame('abc123', $reloaded->clientId());
    }

    public function testIsSandboxReflectsEnvironment(): void
    {
        $config = PluginConfig::fromFile($this->path);
        self::assertTrue($config->isSandbox());
    }
}
