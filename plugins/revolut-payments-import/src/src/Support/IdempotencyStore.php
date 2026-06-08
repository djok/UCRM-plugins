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
        $this->processed[$id] = true;
        $this->flush();
    }

    private function flush(): void
    {
        $json = json_encode(array_keys($this->processed), JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode idempotency store.');
        }
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0770, true);
        }
        if (file_put_contents($this->path, $json) === false) {
            throw new \RuntimeException('Failed to write idempotency store to ' . $this->path);
        }
    }
}
