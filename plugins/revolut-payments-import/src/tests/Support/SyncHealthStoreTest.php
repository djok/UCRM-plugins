<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Support;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Support\SyncHealth;
use RevolutPaymentsImport\Support\SyncHealthStore;

final class SyncHealthStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/revolut-health-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testMissingFileLoadsEmptyHealth(): void
    {
        $health = (new SyncHealthStore($this->path))->load();

        self::assertNull($health->lastSuccessAt);
        self::assertNull($health->lastError);
        self::assertFalse($health->reauthRequired);
    }

    public function testCorruptFileLoadsEmptyHealth(): void
    {
        file_put_contents($this->path, 'not json {');

        self::assertNull((new SyncHealthStore($this->path))->load()->lastError);
    }

    public function testRoundTrip(): void
    {
        $store = new SyncHealthStore($this->path);
        $store->save(new SyncHealth(lastSuccessAt: 100, lastErrorAt: 200, lastError: 'boom', lastErrorStatus: 429, reauthRequired: true));

        $loaded = $store->load();
        self::assertSame(100, $loaded->lastSuccessAt);
        self::assertSame(200, $loaded->lastErrorAt);
        self::assertSame('boom', $loaded->lastError);
        self::assertSame(429, $loaded->lastErrorStatus);
        self::assertTrue($loaded->reauthRequired);
    }

    public function testWithErrorAndWithSuccessAreImmutable(): void
    {
        $base = new SyncHealth();
        $errored = $base->withError(500, 'refresh rejected', 401, true);

        self::assertNull($base->lastError, 'original must be unchanged');
        self::assertSame('refresh rejected', $errored->lastError);
        self::assertSame(401, $errored->lastErrorStatus);
        self::assertTrue($errored->reauthRequired);

        $recovered = $errored->withSuccess(600);
        self::assertSame(600, $recovered->lastSuccessAt);
        self::assertFalse($recovered->reauthRequired, 'success clears the re-auth flag');
        self::assertNull($recovered->lastError, 'success clears the standing error');
        self::assertNull($recovered->lastErrorStatus, 'success clears the standing error status');
    }

    public function testRecordHelpersPersist(): void
    {
        $store = new SyncHealthStore($this->path);
        $store->recordError(10, 'down', 503, false);
        $store->recordSuccess(20);

        $loaded = $store->load();
        self::assertSame(20, $loaded->lastSuccessAt);
        self::assertNull($loaded->lastErrorAt, 'a later success clears the standing error');
        self::assertFalse($loaded->reauthRequired);
    }
}
