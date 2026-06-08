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
}
