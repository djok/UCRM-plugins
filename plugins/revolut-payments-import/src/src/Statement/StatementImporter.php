<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Statement;

use RevolutPaymentsImport\Matching\ClientRepository;
use RevolutPaymentsImport\Support\IdempotencyStore;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\IncomingPayment;
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
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly PaymentRecorder $payments,
        private readonly IdempotencyStore $idempotency,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param list<array{id:string,date:string,amount:float,currency:string,reference:string,senderName:string,senderIban:string}> $rows
     * @return int number of payments imported
     */
    public function import(array $rows): int
    {
        $imported = 0;
        foreach ($rows as $row) {
            if ($this->idempotency->isProcessed($row['id'])) {
                continue;
            }

            $client = $row['senderIban'] !== '' ? $this->clients->findClientByIban($row['senderIban']) : null;
            $clientId = isset($client['id']) ? (int) $client['id'] : null;

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
            $this->payments->record($payment);
            $this->idempotency->markProcessed($row['id']);
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
}
