<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Status;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Revolut\TransactionSource;
use RevolutPaymentsImport\Status\Reimporter;
use RevolutPaymentsImport\Support\IdempotencyStore;

final class ReimporterTest extends TestCase
{
    private const TX = '055d7bd0-0015-e343-0b40-032d2bd81330';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/revolut-reimport-' . uniqid();
        mkdir($this->dir);
        // A GONE row: processed earlier, its payment deleted in UISP since.
        (new IdempotencyStore($this->storePath()))->markProcessed(self::TX);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function lockPath(): string
    {
        return $this->dir . '/reimport.lock';
    }

    private function storePath(): string
    {
        return $this->dir . '/processed.json';
    }

    private function stillProcessed(): bool
    {
        return (new IdempotencyStore($this->storePath()))->isProcessed(self::TX);
    }

    private function source(?array $transaction): TransactionSource
    {
        return new class($transaction) implements TransactionSource {
            public function __construct(private readonly ?array $transaction)
            {
            }

            public function getTransaction(string $id): ?array
            {
                return $this->transaction;
            }
        };
    }

    /** True if another handle can take the lock right now (i.e. nobody holds it). */
    private function lockIsFree(): bool
    {
        $handle = fopen($this->lockPath(), 'c');
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $free;
    }

    public function testReimportsWithoutEverRemovingTheIdFromTheHistory(): void
    {
        // The id must stay "processed" the whole time: the webhook, the cron and the
        // statement import do not take the re-import lock and would otherwise see it
        // as new and record a second payment.
        $seen = [];
        $reimporter = new Reimporter(
            $this->source(['id' => self::TX]),
            static fn (array $tx, string $id): bool => false,
            function (array $tx) use (&$seen): void {
                $seen[] = [$tx['id'], $this->stillProcessed()];
            },
            $this->lockPath(),
        );

        self::assertSame(Reimporter::REIMPORTED, $reimporter->reimport(self::TX));
        self::assertSame([[self::TX, true]], $seen);
        self::assertTrue($this->stillProcessed());
    }

    public function testExistingPaymentShortCircuitsWithoutImporting(): void
    {
        $calls = 0;
        $reimporter = new Reimporter(
            $this->source(['id' => self::TX]),
            static fn (array $tx, string $id): bool => true,
            function () use (&$calls): void {
                $calls++;
            },
            $this->lockPath(),
        );

        self::assertSame(Reimporter::ALREADY, $reimporter->reimport(self::TX));
        self::assertSame(0, $calls);
    }

    public function testUnknownTransactionIsNotFound(): void
    {
        $calls = 0;
        $reimporter = new Reimporter(
            $this->source(null),
            static fn (array $tx, string $id): bool => false,
            function () use (&$calls): void {
                $calls++;
            },
            $this->lockPath(),
        );

        self::assertSame(Reimporter::NOT_FOUND, $reimporter->reimport(self::TX));
        self::assertSame(0, $calls);
    }

    public function testCheckAndImportRunUnderTheServerSideLock(): void
    {
        $freeDuringCheck = null;
        $freeDuringImport = null;
        $reimporter = new Reimporter(
            $this->source(['id' => self::TX]),
            function () use (&$freeDuringCheck): bool {
                $freeDuringCheck = $this->lockIsFree();

                return false;
            },
            function () use (&$freeDuringImport): void {
                $freeDuringImport = $this->lockIsFree();
            },
            $this->lockPath(),
        );

        $reimporter->reimport(self::TX);

        self::assertFalse($freeDuringCheck, 'lock must be held during the check');
        self::assertFalse($freeDuringImport, 'lock must be held during the import');
        self::assertTrue($this->lockIsFree(), 'lock is released afterwards');
    }

    public function testDoubleSubmitCreatesOnePayment(): void
    {
        // The second submit (serialized behind the lock) sees the payment the first
        // one created — it carries the transaction key — and does nothing.
        $payments = [];
        // A plain closure (not an arrow fn, which would copy $payments by value)
        // so both Reimporter instances share the same simulated UISP payments.
        $make = function () use (&$payments): Reimporter {
            return new Reimporter(
                $this->source(['id' => self::TX]),
                static function (array $tx, string $id) use (&$payments): bool {
                    return in_array($id, $payments, true);
                },
                static function (array $tx) use (&$payments): void {
                    $payments[] = $tx['id'];
                },
                $this->lockPath(),
            );
        };

        self::assertSame(Reimporter::REIMPORTED, $make()->reimport(self::TX));
        self::assertSame(Reimporter::ALREADY, $make()->reimport(self::TX));
        self::assertSame([self::TX], $payments);
    }

    public function testFailedImportReleasesTheLockAndKeepsTheRowReimportable(): void
    {
        $reimporter = new Reimporter(
            $this->source(['id' => self::TX]),
            static fn (array $tx, string $id): bool => false,
            static function (): void {
                throw new \RuntimeException('UISP down');
            },
            $this->lockPath(),
        );

        try {
            $reimporter->reimport(self::TX);
            self::fail('expected the failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('UISP down', $e->getMessage());
        }

        self::assertTrue($this->lockIsFree());
        // Nothing was forgotten, so the status page still shows the row as
        // "processed but missing" with its re-import button.
        self::assertTrue($this->stillProcessed());
    }
}
