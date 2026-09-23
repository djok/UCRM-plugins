<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Support;

/**
 * Persists the latest SyncHealth to data/health.json. Best-effort: a corrupt or
 * missing file reads as empty health, and a write failure is swallowed (health
 * is diagnostic, never load-bearing — it must not break a cron run).
 */
final class SyncHealthStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function load(): SyncHealth
    {
        if (! is_file($this->path)) {
            return new SyncHealth();
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);

        return is_array($decoded) ? SyncHealth::fromArray($decoded) : new SyncHealth();
    }

    public function save(SyncHealth $health): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $json = json_encode($health->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            @file_put_contents($this->path, $json, LOCK_EX);
        }
    }

    public function recordSuccess(int $at): void
    {
        $this->save($this->load()->withSuccess($at));
    }

    public function recordError(int $at, string $message, ?int $status = null, bool $reauthRequired = false): void
    {
        $this->save($this->load()->withError($at, $message, $status, $reauthRequired));
    }
}
