<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Support;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Support\FileLock;

final class FileLockTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/revolut-lock-' . uniqid() . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function isFree(): bool
    {
        $handle = fopen($this->path, 'c');
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $free;
    }

    public function testRunsTheCriticalSectionUnderTheLockAndReturnsItsValue(): void
    {
        $freeInside = null;
        $result = (new FileLock($this->path))->synchronized(function () use (&$freeInside): string {
            $freeInside = $this->isFree();

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertFalse($freeInside, 'held during the critical section');
        self::assertTrue($this->isFree(), 'released afterwards');
    }

    public function testReleasesTheLockWhenTheCriticalSectionThrows(): void
    {
        try {
            (new FileLock($this->path))->synchronized(static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('expected the exception to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertTrue($this->isFree());
    }
}
