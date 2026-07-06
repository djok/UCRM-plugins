<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Statement;

use RevolutPaymentsImport\Matching\ClientRepository;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\AccountLearner;
use RevolutPaymentsImport\Ucrm\PaymentFinderInterface;
use RevolutPaymentsImport\Ucrm\PaymentUpdaterInterface;

/**
 * Second chance for statement rows whose transaction was already imported:
 * finds the existing UISP payment and, when it is unassigned, attaches the
 * client recognized by sender IBAN (then sender name). Optionally teaches the
 * client's sender identities so future webhook payments (which carry no IBAN)
 * match by name automatically.
 */
final class StatementReMatcher
{
    public function __construct(
        private readonly PaymentFinderInterface $payments,
        private readonly PaymentUpdaterInterface $updater,
        private readonly ClientRepository $clients,
        private readonly AccountLearner $learner,
        private readonly Logger $logger,
        private readonly bool $learnSenders,
    ) {
    }

    /** @param array{id:string,date:string,amount:float,currency:string,reference:string,senderName:string,senderIban:string} $row */
    public function reMatch(array $row): void
    {
        $payment = $this->payments->findForStatementRow($row['id'], $row['date'], $row['amount']);
        if ($payment === null) {
            return;
        }

        $client = null;
        if ($row['senderIban'] !== '') {
            $client = $this->clients->findClientByIban($row['senderIban']);
        }
        if ($client === null && $row['senderName'] !== '') {
            $client = $this->clients->findClientByIban($row['senderName']);
        }
        $clientId = isset($client['id']) ? (int) $client['id'] : null;

        $paymentClientId = isset($payment['clientId']) && $payment['clientId'] !== null ? (int) $payment['clientId'] : null;
        $identities = array_values(array_filter([$row['senderIban'], $row['senderName']], static fn (string $v): bool => $v !== ''));

        if ($paymentClientId !== null) {
            // Already attached (manually or by matching) — just learn identities.
            if ($this->learnSenders && $identities !== []) {
                $this->learner->learn($paymentClientId, $identities);
            }

            return;
        }

        if ($clientId === null) {
            $this->logger->info(sprintf('Re-match: %s — no client recognized for "%s"; leaving unassigned.', $row['id'], $row['senderName']));

            return;
        }

        $paymentId = (int) ($payment['id'] ?? 0);
        if (! $this->updater->attachClient($paymentId, $clientId)) {
            $this->logger->error(sprintf(
                'Re-match: could not attach payment %d to client %d (UISP may not support PATCHing payments) — attach it manually.',
                $paymentId,
                $clientId,
            ));
        } else {
            $this->logger->info(sprintf('Re-match: payment %d attached to client %d (%s).', $paymentId, $clientId, $row['senderName']));
        }

        if ($this->learnSenders && $identities !== []) {
            $this->learner->learn($clientId, $identities);
        }
    }
}
