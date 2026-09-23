<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Support;

/**
 * Records processed Revolut transaction ids to guarantee at-most-once
 * payment creation across webhook retries, duplicates, and reconciliation.
 *
 * Readers take a shared lock and writers an exclusive one on the same file, so a
 * reader never sees a half-rewritten file. A non-empty file that does not decode
 * is treated as an ERROR, never as an empty history: an empty history would make
 * every transaction look new (mass duplicate import), and rewriting over it would
 * wipe the record for good.
 */
final class IdempotencyStore
{
    /** @var array<string,true> */
    private array $processed;

    public function __construct(private readonly string $path)
    {
        $this->processed = $this->load();
    }

    public function isProcessed(string $id): bool
    {
        return isset($this->processed[$id]);
    }

    /**
     * Re-reads the history from disk. A long-running process (the scheduled run)
     * calls this right before recording, so it sees what a concurrent process (a
     * webhook) recorded after this instance was created.
     */
    public function refresh(): void
    {
        $this->processed = $this->load();
    }

    public function markProcessed(string $id): void
    {
        if (isset($this->processed[$id])) {
            return;
        }
        $this->mutate(static function (array $map) use ($id): array {
            $map[$id] = true;

            return $map;
        });
    }

    /** @return array<string,true> */
    private function load(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open idempotency store at ' . $this->path);
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                throw new \RuntimeException('Failed to lock idempotency store at ' . $this->path);
            }

            try {
                $contents = stream_get_contents($handle);

                return $this->decode($contents === false ? '' : $contents);
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Applies a mutation to the CURRENT on-disk contents under an exclusive
     * lock, so concurrent writers (webhook, cron, and the status page's
     * re-import action all construct their own IdempotencyStore instance)
     * merge their changes instead of clobbering each other with a stale
     * in-memory snapshot. Read-modify-write happens entirely while the lock
     * is held; $this->processed is refreshed from the merged result.
     *
     * @param callable(array<string,true>):array<string,true> $mutator
     */
    private function mutate(callable $mutator): void
    {
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0770, true);
        }

        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open idempotency store at ' . $this->path);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Failed to lock idempotency store at ' . $this->path);
            }

            try {
                $contents = stream_get_contents($handle);
                // Throws on a corrupt file BEFORE anything is truncated.
                $current = $this->decode($contents === false ? '' : $contents);

                $map = $mutator($current);

                $json = json_encode(array_keys($map), JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new \RuntimeException('Failed to encode idempotency store.');
                }

                if (! ftruncate($handle, 0)) {
                    throw new \RuntimeException('Failed to truncate idempotency store at ' . $this->path);
                }
                rewind($handle);
                if (fwrite($handle, $json) === false) {
                    throw new \RuntimeException('Failed to write idempotency store to ' . $this->path);
                }
                fflush($handle);

                $this->processed = $map;
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string,true> */
    private function decode(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException(sprintf(
                'Idempotency store %s is corrupt (%d bytes that are not a JSON list) — imports are stopped to avoid duplicates. '
                . 'Restore the file from a backup of the plugin data directory; do NOT delete it (every transaction would be imported again).',
                $this->path,
                strlen($contents),
            ));
        }
        $map = [];
        foreach ($decoded as $id) {
            if (is_string($id)) {
                $map[$id] = true;
            }
        }

        return $map;
    }
}
