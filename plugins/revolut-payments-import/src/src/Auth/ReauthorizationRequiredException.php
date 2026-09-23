<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Auth;

/**
 * The stored refresh token was rejected by Revolut (HTTP 400/401 on the refresh
 * grant) — the app must be re-authorized. Distinct from a transient RevolutApi
 * failure: it will NOT recover on its own, so surfaces a clear operator action
 * instead of retrying forever. The token is deliberately NOT auto-cleared (a
 * transient 401 must not force re-consent); the message tells the operator how.
 */
final class ReauthorizationRequiredException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
