<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Status;

use RevolutPaymentsImport\Revolut\TransactionSource;
use RevolutPaymentsImport\Support\FileLock;

/**
 * The status page's „Добави наново" action: re-imports one transaction whose
 * payment was deleted in UISP.
 *
 * Two guards make it duplicate-safe:
 *  - The transaction id is never removed from the processed history; the import
 *    goes through EventProcessor::reprocessTransaction, which bypasses only that
 *    check. The webhook, the cron and the statement import therefore keep
 *    skipping the id and cannot record it concurrently — and if the import
 *    fails, the row stays re-importable.
 *  - "Already imported?" -> import is check-then-act, so it runs under an
 *    exclusive server-side lock: a concurrent submit (a double-click beating the
 *    button's client-side disable, two admins, a replayed POST) waits, then sees
 *    the payment the first one created (it carries the transaction key) and
 *    does nothing.
 */
final class Reimporter
{
    public const REIMPORTED = 'reimported';
    public const ALREADY = 'already';
    public const NOT_FOUND = 'not-found';

    /**
     * @param \Closure(array<mixed>, string): bool $isAlreadyImported exact lookup by transaction key
     * @param \Closure(array<mixed>): void $import records the payment (EventProcessor::reprocessTransaction)
     */
    public function __construct(
        private readonly TransactionSource $transactions,
        private readonly \Closure $isAlreadyImported,
        private readonly \Closure $import,
        private readonly string $lockPath,
    ) {
    }

    public function reimport(string $transactionId): string
    {
        // A separate lock from the import lock the recording itself takes: the
        // re-import holds this one and acquires the import lock inside, while the
        // webhook and the scheduled run take only the import lock — no cycle.
        return (new FileLock($this->lockPath))->synchronized(function () use ($transactionId): string {
            $transaction = $this->transactions->getTransaction($transactionId);
            if ($transaction === null) {
                return self::NOT_FOUND;
            }
            if (($this->isAlreadyImported)($transaction, $transactionId)) {
                return self::ALREADY;
            }

            ($this->import)($transaction);

            return self::REIMPORTED;
        });
    }
}
