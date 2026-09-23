<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Support;

/**
 * Immutable snapshot of the plugin's sync health, shown on the status page so the
 * operator can tell "Revolut down / re-authorize needed / last successful sync"
 * apart from "no incoming payments". Persisted as data/health.json.
 */
final class SyncHealth
{
    public function __construct(
        public readonly ?int $lastSuccessAt = null,
        public readonly ?int $lastErrorAt = null,
        public readonly ?string $lastError = null,
        public readonly ?int $lastErrorStatus = null,
        public readonly bool $reauthRequired = false,
    ) {
    }

    public function withSuccess(int $at): self
    {
        // A success is a clean slate: it clears the re-auth flag AND the standing
        // error, so the status banner does not show a stale error next to a fresh
        // successful sync.
        return new self($at, null, null, null, false);
    }

    public function withError(int $at, string $message, ?int $status = null, bool $reauthRequired = false): self
    {
        return new self(
            $this->lastSuccessAt,
            $at,
            $message,
            $status,
            $reauthRequired || $this->reauthRequired,
        );
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['lastSuccessAt']) ? (int) $data['lastSuccessAt'] : null,
            isset($data['lastErrorAt']) ? (int) $data['lastErrorAt'] : null,
            isset($data['lastError']) && is_string($data['lastError']) ? $data['lastError'] : null,
            isset($data['lastErrorStatus']) ? (int) $data['lastErrorStatus'] : null,
            (bool) ($data['reauthRequired'] ?? false),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'lastSuccessAt' => $this->lastSuccessAt,
            'lastErrorAt' => $this->lastErrorAt,
            'lastError' => $this->lastError,
            'lastErrorStatus' => $this->lastErrorStatus,
            'reauthRequired' => $this->reauthRequired,
        ];
    }
}
