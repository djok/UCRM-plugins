<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Support;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Auth\ReauthorizationRequiredException;
use RevolutPaymentsImport\Revolut\RevolutApiException;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Support\StageRunner;
use RevolutPaymentsImport\Support\SyncHealthStore;

final class StageRunnerTest extends TestCase
{
    private string $path;
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/revolut-sr-' . uniqid() . '.json';
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function runner(int $now = 1000): StageRunner
    {
        $logger = new Logger(function (string $l): void {
            $this->lines[] = $l;
        });

        return new StageRunner($logger, new SyncHealthStore($this->path), $now);
    }

    public function testFailingStageDoesNotStopLaterStages(): void
    {
        $runner = $this->runner();
        $ran = [];

        self::assertTrue($runner->run('first', function () use (&$ran): void {
            $ran[] = 'first';
        }));
        self::assertFalse($runner->run('boom', function (): void {
            throw new \RuntimeException('kaboom');
        }));
        self::assertTrue($runner->run('third', function () use (&$ran): void {
            $ran[] = 'third';
        }));

        self::assertSame(['first', 'third'], $ran);
        self::assertNotEmpty(array_filter($this->lines, static fn (string $l): bool => str_contains($l, 'stage "boom" failed')));
    }

    public function testGenericFailureRecordsHealthButNotRevolutUnavailable(): void
    {
        $runner = $this->runner(1000);
        $runner->run('boom', function (): void {
            throw new \RuntimeException('kaboom');
        });

        self::assertFalse($runner->revolutUnavailable());
        $health = (new SyncHealthStore($this->path))->load();
        self::assertSame(1000, $health->lastErrorAt);
        self::assertStringContainsString('kaboom', (string) $health->lastError);
        self::assertNull($health->lastSuccessAt);
        self::assertFalse($health->reauthRequired);
    }

    public function testRevolutRateLimitFlagsUnavailableAndRecordsStatus(): void
    {
        $runner = $this->runner();
        $runner->run('reconcile', function (): void {
            throw new RevolutApiException('Revolut API 429 on GET /api/1.0/transactions', 'GET', '/api/1.0/transactions', 429, 3162, 30);
        });

        self::assertTrue($runner->revolutUnavailable());
        $health = (new SyncHealthStore($this->path))->load();
        self::assertSame(429, $health->lastErrorStatus);
        self::assertFalse($health->reauthRequired);
        self::assertNotEmpty(array_filter($this->lines, static fn (string $l): bool => str_contains($l, 'rate limited')));
    }

    public function testTransientServerErrorDoesNotBlockLaterStages(): void
    {
        // A 5xx on one endpoint (e.g. the failed-events 500) must NOT flip
        // revolutUnavailable — a different endpoint like reconcile must still run.
        $runner = $this->runner();
        $runner->run('replay-failed-events', function (): void {
            throw new RevolutApiException('Revolut API 500 on GET /api/2.0/webhooks/x/failed-events', 'GET', '/api/2.0/webhooks/x/failed-events', 500, 5000);
        });

        self::assertFalse($runner->revolutUnavailable(), 'a single-endpoint 5xx must not back off other stages');
        $health = (new SyncHealthStore($this->path))->load();
        self::assertSame(500, $health->lastErrorStatus);
    }

    public function testReauthorizationRequiredSetsHealthFlag(): void
    {
        $runner = $this->runner();
        $runner->run('revolut-auth', function (): void {
            throw new ReauthorizationRequiredException('Revolut rejected the stored refresh token (HTTP 400).', 400);
        });

        self::assertTrue($runner->revolutUnavailable());
        $health = (new SyncHealthStore($this->path))->load();
        self::assertTrue($health->reauthRequired);
        self::assertSame(400, $health->lastErrorStatus);
    }
}
