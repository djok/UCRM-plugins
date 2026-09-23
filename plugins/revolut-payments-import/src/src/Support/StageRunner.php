<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Support;

use RevolutPaymentsImport\Auth\ReauthorizationRequiredException;
use RevolutPaymentsImport\Revolut\RevolutApiException;

/**
 * Runs each cron stage in isolation: a stage that throws is logged and recorded
 * in SyncHealth, but the remaining stages still run. This is what keeps the
 * Revolut-independent statement CSV import (and cursor persistence) alive when a
 * Revolut call fails — the original single-try/catch coupled them all together,
 * so one blocked-account 401 (or the failed-events 500 seen in production) killed
 * the whole run.
 */
final class StageRunner
{
    private bool $revolutUnavailable = false;

    public function __construct(
        private readonly Logger $logger,
        private readonly SyncHealthStore $health,
        private readonly int $now,
    ) {
    }

    /**
     * Runs one stage; returns true on success, false if it threw. A Revolut
     * failure additionally flips revolutUnavailable() so the caller can skip the
     * remaining Revolut-dependent stages this run.
     */
    public function run(string $name, callable $stage): bool
    {
        try {
            $stage();

            return true;
        } catch (ReauthorizationRequiredException $e) {
            $this->revolutUnavailable = true;
            $this->logger->error(sprintf('main: stage "%s" failed — %s', $name, $e->getMessage()));
            $this->health->recordError($this->now, $e->getMessage(), $e->statusCode, true);

            return false;
        } catch (RevolutApiException $e) {
            // Back off the remaining Revolut stages ONLY for rate limiting (429) or
            // an auth failure (401/403) — there, continuing definitely won't help and
            // only adds load. A one-off 5xx/connect error on a single endpoint (e.g.
            // the failed-events 500 Revolut returns) must NOT block a different,
            // healthy endpoint like reconcile — that stage runs and is isolated too.
            if ($e->isRateLimited() || $e->isAuthFailure()) {
                $this->revolutUnavailable = true;
            }
            $suffix = $e->isRateLimited() ? ' (rate limited — backing off Revolut this run)' : '';
            $this->logger->error(sprintf('main: stage "%s" failed — %s%s', $name, $e->getMessage(), $suffix));
            $this->health->recordError($this->now, $e->getMessage(), $e->statusCode, false);

            return false;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('main: stage "%s" failed — %s', $name, $e->getMessage()));
            $this->health->recordError($this->now, $e->getMessage());

            return false;
        }
    }

    /** True once any stage failed with a Revolut transport/auth error. */
    public function revolutUnavailable(): bool
    {
        return $this->revolutUnavailable;
    }
}
