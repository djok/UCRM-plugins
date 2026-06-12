<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Webhook;

use RevolutPaymentsImport\Matching\ClientRepository;
use RevolutPaymentsImport\Revolut\CounterpartySource;
use RevolutPaymentsImport\Revolut\TransactionSource;
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\IncomingPayment;
use RevolutPaymentsImport\Ucrm\PaymentRecorder;

/**
 * Turns a Revolut event/transaction into a UCRM payment.
 * Incoming bank payment = state 'completed', type in {transfer, topup},
 * a leg with positive amount. Sender IBAN comes from the counterparty lookup;
 * the client is matched by that IBAN, else the payment is recorded unassigned.
 */
final class EventProcessor
{
    private const INCOMING_TYPES = ['transfer', 'topup'];

    /** @var list<string> lowercase account ids; empty = import from all accounts */
    private readonly array $allowedAccountIds;

    /** @param list<string> $allowedAccountIds */
    public function __construct(
        private readonly TransactionSource $transactions,
        private readonly CounterpartySource $counterparties,
        private readonly ClientRepository $clients,
        private readonly PaymentRecorder $payments,
        private readonly IdempotencyStore $idempotency,
        private readonly Logger $logger,
        array $allowedAccountIds = [],
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
        $id = $transaction['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return;
        }
        if ($this->idempotency->isProcessed($id)) {
            return;
        }

        $state = $transaction['state'] ?? null;
        if ($state !== 'completed') {
            // Not final yet — do NOT mark processed; a later event will complete it.
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

        if ($this->allowedAccountIds !== []) {
            $accountId = strtolower((string) ($leg['account_id'] ?? ''));
            if (! in_array($accountId, $this->allowedAccountIds, true)) {
                $this->idempotency->markProcessed($id); // terminal: account not selected
                $this->logger->info(sprintf('Transaction %s on account %s not selected; ignored.', $id, $accountId));

                return;
            }
        }

        $sender = $this->resolveSender($leg);
        $clientId = $this->matchClient($sender['iban']);

        $completedAt = $transaction['completed_at'] ?? $transaction['created_at'] ?? null;
        $payment = new IncomingPayment(
            amount: (float) $leg['amount'],
            currencyCode: (string) ($leg['currency'] ?? ''),
            clientId: $clientId,
            note: $this->buildNote($sender, (string) ($transaction['reference'] ?? '')),
            externalId: $id,
            createdDate: is_string($completedAt) && $completedAt !== '' ? $completedAt : null,
        );
        $this->payments->record($payment);
        $this->idempotency->markProcessed($id);

        $this->logger->info(sprintf(
            'Recorded payment for transaction %s: %.2f %s, client=%s.',
            $id,
            $payment->amount,
            $payment->currencyCode,
            $clientId === null ? 'unassigned' : (string) $clientId,
        ));
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
        // description ("Payment from ACME LTD") is then the only sender info.
        if ($name === null) {
            $description = $leg['description'] ?? null;
            if (is_string($description) && $description !== '') {
                $name = (string) preg_replace('/^payment from\s+/i', '', $description);
            }
        }

        return ['iban' => $iban, 'name' => $name];
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
