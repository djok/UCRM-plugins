<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Support;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Support\IdempotencyStore;

final class IdempotencyStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/revolut-proc-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testUnknownIdIsNotProcessed(): void
    {
        $store = new IdempotencyStore($this->path);
        self::assertFalse($store->isProcessed('tx-1'));
    }

    public function testMarkedIdIsProcessedAndPersists(): void
    {
        $store = new IdempotencyStore($this->path);
        $store->markProcessed('tx-1');

        self::assertTrue($store->isProcessed('tx-1'));

        $reloaded = new IdempotencyStore($this->path);
        self::assertTrue($reloaded->isProcessed('tx-1'));
        self::assertFalse($reloaded->isProcessed('tx-2'));
    }

    public function testMarkingIsIdempotent(): void
    {
        $store = new IdempotencyStore($this->path);
        $store->markProcessed('tx-1');
        $store->markProcessed('tx-1');

        $reloaded = new IdempotencyStore($this->path);
        self::assertTrue($reloaded->isProcessed('tx-1'));
    }

    public function testForgetRemovesIdAndPersists(): void
    {
        $store = new IdempotencyStore($this->path);
        $store->markProcessed('tx-1');
        $store->markProcessed('tx-2');

        $store->forget('tx-1');

        self::assertFalse($store->isProcessed('tx-1'));
        self::assertTrue($store->isProcessed('tx-2'));
        $reloaded = new IdempotencyStore($this->path);
        self::assertFalse($reloaded->isProcessed('tx-1'));
        self::assertTrue($reloaded->isProcessed('tx-2'));
    }

    public function testForgetUnknownIdIsHarmless(): void
    {
        $store = new IdempotencyStore($this->path);
        $store->markProcessed('tx-1');

        $store->forget('tx-unknown');

        self::assertTrue($store->isProcessed('tx-1'));
    }

    public function testConcurrentInstancesDoNotLoseEachOthersWrites(): void
    {
        $a = new IdempotencyStore($this->path);
        $b = new IdempotencyStore($this->path);

        $a->markProcessed('tx-a');
        $b->markProcessed('tx-b');

        $reloaded = new IdempotencyStore($this->path);
        self::assertTrue($reloaded->isProcessed('tx-a'));
        self::assertTrue($reloaded->isProcessed('tx-b'));
    }
}
