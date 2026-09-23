<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Support;

/**
 * An exclusive, cross-process lock on a file (flock). The webhook, the scheduled
 * run and the status page are separate PHP processes; this is how they agree on
 * "only one of us checks-and-records at a time". The lock is released when the
 * critical section ends, throws, or the process dies.
 */
final class FileLock
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @template T
     * @param callable(): T $critical
     * @return T
     */
    public function synchronized(callable $critical): mixed
    {
        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the lock file ' . $this->path);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot acquire the lock ' . $this->path);
            }

            try {
                return $critical();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
