<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

/**
 * A Revolut Business API call that failed with an HTTP error or was unreachable.
 * Carries the classified status so callers can react (abort Revolut stages on a
 * rate limit, signal re-authorization on an auth failure, retry transient 5xx)
 * instead of parsing Guzzle's free-text message. The message is already
 * human-readable and free of the request body/headers beyond a short excerpt,
 * so it is safe to log (after Logger::redact).
 */
final class RevolutApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $method,
        public readonly string $path,
        public readonly ?int $statusCode = null,
        public readonly ?int $revolutCode = null,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** 401/403 — the token or the app's access was rejected. */
    public function isAuthFailure(): bool
    {
        return $this->statusCode === 401 || $this->statusCode === 403;
    }

    /** 429 — too many requests; back off (see retryAfter). */
    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }

    /** 5xx or a connection failure — likely temporary, safe to retry later. */
    public function isTransient(): bool
    {
        return $this->statusCode === null || $this->statusCode >= 500;
    }
}
