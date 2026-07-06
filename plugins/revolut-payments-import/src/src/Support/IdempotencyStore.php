<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Support;

/**
 * Records processed Revolut transaction ids to guarantee at-most-once
 * payment creation across webhook retries, duplicates, and reconciliation.
 */
final class IdempotencyStore
{
    /** @var array<string,true> */
    private array $processed;

    public function __construct(private readonly string $path)
    {
        $this->processed = $this->load();
    }

    /** @return array<string,true> */
    private function load(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($decoded)) {
            return [];
        }
        $map = [];
        foreach ($decoded as $id) {
            if (is_string($id)) {
                $map[$id] = true;
            }
        }

        return $map;
    }

    public function isProcessed(string $id): bool
    {
        return isset($this->processed[$id]);
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

    /**
     * Removes an id so the transaction can be imported again — used by the
     * status page's explicit re-import action when the payment was deleted
     * in UISP. Reconciliation itself never forgets ids.
     */
    public function forget(string $id): void
    {
        if (! isset($this->processed[$id])) {
            return;
        }
        $this->mutate(static function (array $map) use ($id): array {
            unset($map[$id]);

            return $map;
        });
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
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            return [];
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
