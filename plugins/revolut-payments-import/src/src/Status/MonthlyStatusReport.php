<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Status;

/**
 * Pure reconciliation logic for the status page: filters raw Revolut
 * transactions down to in-scope incoming transfers (same rules as
 * Webhook\EventProcessor — keep in sync) and resolves each transfer's
 * UISP payment status. No I/O; callers fetch and render.
 */
final class MonthlyStatusReport
{
    /** Keep in sync with EventProcessor::INCOMING_TYPES. */
    private const INCOMING_TYPES = ['transfer', 'topup'];

    /** @var list<string> lowercase account ids; empty = all accounts */
    private readonly array $allowedAccountIds;

    /** @param list<string> $allowedAccountIds */
    public function __construct(array $allowedAccountIds = [])
    {
        $this->allowedAccountIds = array_values(array_map(
            static fn ($id): string => strtolower(trim((string) $id)),
            $allowedAccountIds,
        ));
    }

    /**
     * @param list<array<mixed>> $transactions raw Revolut transactions
     * @param list<array<mixed>> $payments raw UISP payments of the same month
     * @param callable(string):bool $isProcessed idempotency-store lookup
     * @return list<StatusRow>
     */
    public function build(array $transactions, array $payments, callable $isProcessed): array
    {
        $rows = [];
        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }
            $id = $transaction['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            if (($transaction['state'] ?? null) !== 'completed') {
                continue;
            }
            if (! in_array($transaction['type'] ?? null, self::INCOMING_TYPES, true)) {
                continue;
            }
            $leg = $this->incomingLeg($transaction);
            if ($leg === null) {
                continue;
            }
            if ($this->allowedAccountIds !== []) {
                $accountId = strtolower((string) ($leg['account_id'] ?? ''));
                if (! in_array($accountId, $this->allowedAccountIds, true)) {
                    continue;
                }
            }

            $completedAt = $transaction['completed_at'] ?? $transaction['created_at'] ?? '';
            $status = $isProcessed($id) ? StatusRow::STATUS_SKIPPED : StatusRow::STATUS_MISSING;

            $rows[] = new StatusRow(
                transactionId: $id,
                date: substr(is_string($completedAt) ? $completedAt : '', 0, 10),
                amount: (float) $leg['amount'],
                currency: (string) ($leg['currency'] ?? ''),
                sender: $this->senderFromLeg($leg),
                reference: (string) ($transaction['reference'] ?? ''),
                status: $status,
            );
        }

        return $rows;
    }

    /**
     * @param array<mixed> $transaction
     * @return array<mixed>|null first leg with a positive amount
     */
    private function incomingLeg(array $transaction): ?array
    {
        $legs = $transaction['legs'] ?? [];
        if (! is_array($legs)) {
            return null;
        }
        foreach ($legs as $leg) {
            if (is_array($leg) && isset($leg['amount']) && (float) $leg['amount'] > 0.0) {
                return $leg;
            }
        }

        return null;
    }

    /** @param array<mixed> $leg */
    private function senderFromLeg(array $leg): string
    {
        $description = $leg['description'] ?? '';
        if (! is_string($description)) {
            return '';
        }

        return (string) preg_replace('/^(payment from|добавени пари от)\s+/iu', '', $description);
    }
}
