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

    public function testRefreshPicksUpWhatAnotherProcessRecorded(): void
    {
        // A long cron run loads its snapshot once; a webhook records X meanwhile.
        $cron = new IdempotencyStore($this->path);
        (new IdempotencyStore($this->path))->markProcessed('tx-x');

        self::assertFalse($cron->isProcessed('tx-x'), 'the snapshot is stale');
        $cron->refresh();
        self::assertTrue($cron->isProcessed('tx-x'));
    }

    public function testEmptyOrMissingFileIsAnEmptyHistory(): void
    {
        self::assertFalse((new IdempotencyStore($this->path))->isProcessed('tx-1'));
        file_put_contents($this->path, '');
        self::assertFalse((new IdempotencyStore($this->path))->isProcessed('tx-1'));
    }

    public function testCorruptHistoryFailsLoudlyInsteadOfLookingEmpty(): void
    {
        // A torn/corrupt file must never read as "nothing processed" — every
        // transaction would then be imported again.
        file_put_contents($this->path, '["tx-1","tx-');

        $this->expectException(\RuntimeException::class);
        new IdempotencyStore($this->path);
    }

    public function testCorruptHistoryIsNeverOverwritten(): void
    {
        // Writing a fresh map over a corrupt file would wipe the whole history.
        $store = new IdempotencyStore($this->path);
        file_put_contents($this->path, '{corrupt');

        try {
            $store->markProcessed('tx-new');
            self::fail('expected the corrupt history to be refused');
        } catch (\RuntimeException $e) {
            self::assertSame('{corrupt', file_get_contents($this->path), 'history left untouched');
        }
    }
}
