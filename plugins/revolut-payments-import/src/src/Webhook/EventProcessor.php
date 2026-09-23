<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Webhook;

use RevolutPaymentsImport\Matching\ClientRepository;
use RevolutPaymentsImport\Revolut\CounterpartySource;
use RevolutPaymentsImport\Revolut\TransactionShape;
use RevolutPaymentsImport\Revolut\TransactionSource;
use RevolutPaymentsImport\Support\FileLock;
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\IncomingPayment;
use RevolutPaymentsImport\Ucrm\PaymentRecorder;

/**
 * Turns a Revolut event/transaction into a UCRM payment.
 * Incoming bank payment = state 'completed', type in {transfer, topup},
 * a leg with positive amount. Sender IBAN comes from the counterparty lookup;
 * the client is matched by that IBAN, then by sender name, else the payment
 * is recorded unassigned.
 */
final class EventProcessor
{
    private const INCOMING_TYPES = ['transfer', 'topup'];

    /** Terminal Revolut states that never become a payment (blocked account, returned funds). */
    private const TERMINAL_NON_PAYMENT = ['declined', 'failed', 'reverted'];

    /** @var list<string> lowercase account ids; empty = import from all accounts */
    private readonly array $allowedAccountIds;

    /**
     * @param list<string> $allowedAccountIds
     * @param FileLock|null $importLock shared by every process that records payments
     *        (webhook, scheduled run, statement import); null only in tests
     */
    public function __construct(
        private readonly TransactionSource $transactions,
        private readonly CounterpartySource $counterparties,
        private readonly ClientRepository $clients,
        private readonly PaymentRecorder $payments,
        private readonly IdempotencyStore $idempotency,
        private readonly Logger $logger,
        array $allowedAccountIds = [],
        private readonly ?FileLock $importLock = null,
    ) {
        $this->allowedAccountIds = array_values(array_map(
            static fn ($id): string => strtolower(trim((string) $id)),
            $allowedAccountIds,
        ));
    }

    /** @param array<mixed> $event webhook/failed-event envelope */
    public function processEvent(array $event): void
    {
        $id = $event['data']['id'] ?? null;
        if (! is_string($id) || $id === '') {
            $this->logger->error('Event without transaction id; skipping.');

            return;
        }

        // Short-circuit already-processed ids WITHOUT a Revolut fetch: failed-event
        // replay re-delivers the same events for 21 days, and refetching each one
        // wastes the 60 req/min budget (and can 429 the whole run). The only case
        // worth attention is a reversal of a transaction we already recorded.
        if ($this->idempotency->isProcessed($id)) {
            $state = $event['data']['new_state'] ?? $event['data']['state'] ?? null;
            if ($state === 'reverted') {
                $this->alertReverted($id);
            }

            return;
        }

        $transaction = $this->transactions->getTransaction($id);
        if ($transaction === null) {
            $this->logger->error(sprintf('Transaction %s not found; skipping.', $id));

            return;
        }

        $this->processTransaction($transaction);
    }

    /** @param array<mixed> $transaction full Revolut transaction object */
    public function processTransaction(array $transaction): void
    {
        $this->handle($transaction, false);
    }

    /**
     * Explicit admin re-import (status page „Добави наново"): records the payment
     * even though the id is already in the processed history, applying every other
     * rule unchanged. The id is never removed from the history, so the webhook, the
     * cron and the statement import keep skipping it and cannot record it a second
     * time. The caller must first establish that no payment exists (Reimporter does
     * an exact key lookup under a lock).
     *
     * @param array<mixed> $transaction full Revolut transaction object
     */
    public function reprocessTransaction(array $transaction): void
    {
        $this->handle($transaction, true);
    }

    /** @param array<mixed> $transaction */
    private function handle(array $transaction, bool $force): void
    {
        $id = $transaction['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return;
        }
        $state = $transaction['state'] ?? null;

        if (! $force && $this->idempotency->isProcessed($id)) {
            // Already handled. Surface a post-recording reversal (once); stay silent otherwise.
            if ($state === 'reverted') {
                $this->alertReverted($id);
            }

            return;
        }

        if ($state !== 'completed') {
            if (in_array($state, self::TERMINAL_NON_PAYMENT, true)) {
                // Blocked/declined/returned — terminal, will never become a payment.
                // Mark processed so replay/reconciliation stop re-examining it forever.
                $this->idempotency->markProcessed($id);
                $this->logger->info(sprintf('Transaction %s state=%s; terminal, no payment recorded.', $id, (string) $state));

                return;
            }

            // created/pending — not final yet; do NOT mark processed, a later event completes it.
            $this->logger->info(sprintf('Transaction %s state=%s; waiting for completion.', $id, (string) $state));

            return;
        }

        $type = $transaction['type'] ?? null;
        if (! in_array($type, self::INCOMING_TYPES, true)) {
            $this->idempotency->markProcessed($id); // terminal: not a bank transfer
            $this->logger->info(sprintf('Transaction %s type=%s ignored.', $id, (string) $type));

            return;
        }

        $leg = $this->incomingLeg($transaction);
        if ($leg === null) {
            $this->idempotency->markProcessed($id); // terminal: outgoing/zero
            $this->logger->info(sprintf('Transaction %s has no incoming leg; ignored.', $id));

            return;
        }

        if (TransactionShape::isInternalTransfer($transaction)) {
            // terminal: money moved between our own accounts (e.g. Revolut's
            // hold/release during a seizure) — the funds were already received once.
            $this->idempotency->markProcessed($id);
            $this->logger->info(sprintf('Transaction %s is an internal transfer between own accounts; ignored.', $id));

            return;
        }

        if ($this->allowedAccountIds !== []) {
            $accountId = strtolower((string) ($leg['account_id'] ?? ''));
            if (! in_array($accountId, $this->allowedAccountIds, true)) {
                $this->idempotency->markProcessed($id); // terminal: account not selected
                $this->logger->info(sprintf('Transaction %s on account %s not selected; ignored.', $id, $accountId));

                return;
            }
        }

        $sender = $this->resolveSender($leg);
        // The API exposes no sender IBAN for external transfers (verified),
        // so fall back to the sender NAME through the same matching channel —
        // a name stored as a bankAccounts entry on the client acts as an IBAN.
        $clientId = $this->matchClient($sender['iban']) ?? $this->matchClient($sender['name']);

        $completedAt = $transaction['completed_at'] ?? $transaction['created_at'] ?? null;
        $payment = new IncomingPayment(
            amount: (float) $leg['amount'],
            currencyCode: (string) ($leg['currency'] ?? ''),
            clientId: $clientId,
            note: $this->buildNote($sender, (string) ($transaction['reference'] ?? '')),
            externalId: $id,
            createdDate: is_string($completedAt) && $completedAt !== '' ? $completedAt : null,
        );

        if (! $this->recordOnce($id, $payment, $force)) {
            $this->logger->info(sprintf('Transaction %s was recorded concurrently by another run; skipped.', $id));

            return;
        }

        $this->logger->info(sprintf(
            'Recorded payment for transaction %s: %.2f %s, client=%s.',
            $id,
            $payment->amount,
            $payment->currencyCode,
            $clientId === null ? 'unassigned' : (string) $clientId,
        ));
    }

    /**
     * The only check-then-act that must be atomic across processes: the webhook
     * and the scheduled run can reach the same new transaction at the same time,
     * and each decided "not processed" from its own snapshot. Under the shared
     * import lock, re-read the history and record only if nobody else has.
     * The slow network work (Revolut, client matching) stays outside the lock.
     */
    private function recordOnce(string $id, IncomingPayment $payment, bool $force): bool
    {
        $critical = function () use ($id, $payment, $force): bool {
            if (! $force) {
                $this->idempotency->refresh();
                if ($this->idempotency->isProcessed($id)) {
                    return false;
                }
            }
            $this->payments->record($payment);
            $this->idempotency->markProcessed($id);

            return true;
        };

        return $this->importLock !== null ? $this->importLock->synchronized($critical) : $critical();
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

    /**
     * @param array<mixed> $leg
     * @return array{iban:?string,name:?string}
     */
    private function resolveSender(array $leg): array
    {
        $name = null;
        $iban = null;

        $counterpartyId = $leg['counterparty']['id'] ?? null;
        if (is_string($counterpartyId) && $counterpartyId !== '') {
            $counterparty = $this->counterparties->getCounterparty($counterpartyId);
            if ($counterparty !== null) {
                $name = isset($counterparty['name']) ? (string) $counterparty['name'] : null;
                // Prefer the IBAN; fall back to a plain account number (matching
                // normalizes both, and Paysera-style accounts are not IBANs).
                foreach (['iban', 'account_no'] as $field) {
                    foreach ($counterparty['accounts'] ?? [] as $account) {
                        if (! empty($account[$field])) {
                            $iban = (string) $account[$field];
                            break 2;
                        }
                    }
                }
            }
        }

        // Unknown external senders often have no counterparty at all — the leg
        // description ("Payment from ACME LTD" / "Добавени пари от ACME LTD")
        // is then the only sender info.
        if ($name === null) {
            $description = $leg['description'] ?? null;
            if (is_string($description) && $description !== '') {
                $name = (string) preg_replace('/^(payment from|добавени пари от)\s+/iu', '', $description);
            }
        }

        return ['iban' => $iban, 'name' => $name];
    }

    /**
     * Logs the "reverse this payment manually" alert at most once per transaction.
     * Failed-event replay (21-day window) and reconciliation both re-observe the
     * same reversal repeatedly; a namespaced idempotency key keeps it to one line.
     */
    private function alertReverted(string $id): void
    {
        $key = $id . '#reverted-alerted';
        if ($this->idempotency->isProcessed($key)) {
            return;
        }
        $this->idempotency->markProcessed($key);
        $this->logger->error(sprintf(
            'Transaction %s was REVERTED by Revolut after its payment was recorded in UISP — reverse the payment manually.',
            $id,
        ));
    }

    private function matchClient(?string $iban): ?int
    {
        if ($iban === null) {
            return null;
        }
        $client = $this->clients->findClientByIban($iban);

        return isset($client['id']) ? (int) $client['id'] : null;
    }

    /** @param array{iban:?string,name:?string} $sender */
    private function buildNote(array $sender, string $reference): string
    {
        $parts = array_filter([
            $sender['name'],
            $reference !== '' ? $reference : null,
            $sender['iban'],
        ]);

        return 'Revolut: ' . implode(' | ', $parts);
    }
}
