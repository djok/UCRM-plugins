<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Statement;

use RevolutPaymentsImport\Matching\ClientRepository;
use RevolutPaymentsImport\Revolut\TransactionShape;
use RevolutPaymentsImport\Revolut\TransactionSource;
use RevolutPaymentsImport\Support\FileLock;
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\IncomingPayment;
use RevolutPaymentsImport\Ucrm\PaymentLookup;
use RevolutPaymentsImport\Ucrm\PaymentRecorder;

/**
 * Turns parsed statement rows into UCRM payments — Paysera-grade data: the
 * statement always carries the sender IBAN + name, so matching and the note
 * are complete. Shares the idempotency store with the webhook/reconciliation
 * pipeline (Revolut uses the same transaction id in both), so nothing is
 * imported twice.
 */
final class StatementImporter
{
    private int $reMatchFailures = 0;

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly PaymentRecorder $payments,
        private readonly PaymentLookup $existingPayments,
        private readonly IdempotencyStore $idempotency,
        private readonly Logger $logger,
        private readonly ?ReMatcher $reMatcher = null,
        private readonly ?TransactionSource $transactions = null,
        private readonly ?FileLock $importLock = null,
    ) {
    }

    /** Re-match attempts in the last import() whose attach failed — worth retrying. */
    public function reMatchFailures(): int
    {
        return $this->reMatchFailures;
    }

    /**
     * @param list<array{id:string,date:string,amount:float,currency:string,reference:string,senderName:string,senderIban:string}> $rows
     * @return int number of payments imported
     */
    public function import(array $rows): int
    {
        $imported = 0;
        $this->reMatchFailures = 0;
        foreach ($rows as $row) {
            if ($this->idempotency->isProcessed($row['id'])) {
                // Already imported (or deliberately skipped) — give the row a
                // second chance: attach/learn on the existing payment.
                if ($this->reMatcher !== null && ! $this->reMatcher->reMatch($row)) {
                    $this->reMatchFailures++;
                }

                continue;
            }

            if ($this->isVerifiedInternalTransfer($row['id'])) {
                $this->idempotency->markProcessed($row['id']);
                $this->logger->info(sprintf('Statement import: %s is an internal transfer between own accounts; skipped.', $row['id']));

                continue;
            }

            $client = $row['senderIban'] !== '' ? $this->clients->findClientByIban($row['senderIban']) : null;
            if ($client === null && $row['senderName'] !== '') {
                $client = $this->clients->findClientByIban($row['senderName']);
            }
            $clientId = isset($client['id']) ? (int) $client['id'] : null;

            // Duplicate guard: if the matched client already has a payment of the
            // same amount on the same day — entered by hand, or this very transfer's
            // own payment — skip the row instead of duplicating it. The plugin's
            // payments for OTHER transfers do not count (see PaymentLookup).
            if ($clientId !== null && $row['date'] !== ''
                && $this->existingPayments->clientHasPaymentOn($clientId, $row['date'], $row['amount'], $row['id'])
            ) {
                $this->idempotency->markProcessed($row['id']);
                $this->logger->info(sprintf(
                    'Statement import: %s skipped — client %d already has a %.2f %s payment on %s (manual entry or already imported).',
                    $row['id'],
                    $clientId,
                    $row['amount'],
                    $row['currency'],
                    $row['date'],
                ));

                continue;
            }

            $noteParts = array_filter([
                $row['senderName'] !== '' ? $row['senderName'] : null,
                $row['reference'] !== '' ? $row['reference'] : null,
                $row['senderIban'] !== '' ? $row['senderIban'] : null,
            ]);
            $payment = new IncomingPayment(
                amount: $row['amount'],
                currencyCode: $row['currency'],
                clientId: $clientId,
                note: 'Revolut: ' . implode(' | ', $noteParts),
                externalId: $row['id'],
                createdDate: $row['date'] !== '' ? $row['date'] . 'T00:00:00Z' : null,
            );
            if (! $this->recordOnce($row['id'], $payment)) {
                $this->logger->info(sprintf('Statement import: %s was recorded concurrently by another run; skipped.', $row['id']));

                continue;
            }
            $imported++;

            $this->logger->info(sprintf(
                'Statement import: %s %.2f %s -> client %s.',
                $row['id'],
                $row['amount'],
                $row['currency'],
                $clientId === null ? 'unassigned' : (string) $clientId,
            ));
        }

        return $imported;
    }

    /**
     * Records the payment only if no other process (a webhook) recorded the same
     * transfer after this run loaded its history: re-check under the shared
     * import lock, then record and mark.
     */
    private function recordOnce(string $id, IncomingPayment $payment): bool
    {
        $critical = function () use ($id, $payment): bool {
            $this->idempotency->refresh();
            if ($this->idempotency->isProcessed($id)) {
                return false;
            }
            $this->payments->record($payment);
            $this->idempotency->markProcessed($id);

            return true;
        };

        return $this->importLock !== null ? $this->importLock->synchronized($critical) : $critical();
    }

    /**
     * The statement CSV carries no legs, so a single-account export cannot tell a
     * hold/"Release" move from a customer transfer. The statement ID is the API
     * transaction ID, so verify the shape via Revolut when it is reachable. If it
     * is not (the statement is also the Revolut-down fallback), import as before —
     * the parser's duplicate-ID guard still drops moves listed on two accounts.
     */
    private function isVerifiedInternalTransfer(string $id): bool
    {
        if ($this->transactions === null) {
            return false;
        }
        try {
            $transaction = $this->transactions->getTransaction($id);
        } catch (\Throwable $e) {
            $this->logger->info(sprintf('Statement import: could not verify %s with Revolut (%s); importing from the statement.', $id, $e->getMessage()));

            return false;
        }

        return $transaction !== null && TransactionShape::isInternalTransfer($transaction);
    }
}
