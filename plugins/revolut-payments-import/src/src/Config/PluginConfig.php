<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Config;

/**
 * Typed read/write access to the plugin's data/config.json.
 * Immutable getters; mutations go through set() and are flushed by save().
 */
final class PluginConfig
{
    /** @param array<string,mixed> $values */
    private function __construct(
        private array $values,
        private readonly string $path,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? (string) file_get_contents($path) : '{}';
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $decoded = [];
        }

        return new self($decoded, $path);
    }

    private function get(string $key): ?string
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    public function environment(): string
    {
        return $this->get('environment') ?? 'sandbox';
    }

    public function isSandbox(): bool
    {
        return $this->environment() !== 'production';
    }

    public function clientId(): ?string
    {
        return $this->get('clientId');
    }

    public function redirectUri(): ?string
    {
        return $this->get('redirectUri');
    }

    public function authCode(): ?string
    {
        return $this->get('authCode');
    }

    public function refreshToken(): ?string
    {
        return $this->get('refreshToken');
    }

    public function signingSecret(): ?string
    {
        return $this->get('signingSecret');
    }

    public function webhookId(): ?string
    {
        return $this->get('webhookId');
    }

    public function accessToken(): ?string
    {
        return $this->get('accessToken');
    }

    public function accessTokenExpiresAt(): ?int
    {
        $value = $this->get('accessTokenExpiresAt');

        return $value === null ? null : (int) $value;
    }

    public function reconcileFrom(): ?int
    {
        $value = $this->get('reconcileFrom');

        return $value === null ? null : (int) $value;
    }

    public function paymentMethodName(): ?string
    {
        return $this->get('paymentMethodName');
    }

    public function set(string $key, ?string $value): void
    {
        $this->values[$key] = $value;
    }

    public function save(): void
    {
        $json = json_encode($this->values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode plugin config to JSON.');
        }
        if (file_put_contents($this->path, $json) === false) {
            throw new \RuntimeException('Failed to write plugin config to ' . $this->path);
        }
    }
}
