<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

use RevolutPaymentsImport\Matching\ClientMatcher;
use RevolutPaymentsImport\Support\Logger;

/**
 * Teaches UISP a client's sender identities: appends the sender IBAN and name
 * as extra bankAccounts entries so future payments match automatically (the
 * matcher compares normalized accountNumbers — Paysera model). Existing
 * entries are resent verbatim (untouched, including any without an
 * accountNumber) so a replace-semantics PATCH cannot lose data; a
 * merge-semantics PATCH may duplicate them, which is harmless since
 * dedupe-by-key prevents this plugin from ever repeating an entry itself.
 * Best-effort: failures are logged.
 *
 * @see ClientMatcher::normalizeIban()
 */
final class ClientAccountLearner implements AccountLearner
{
    public function __construct(
        private readonly UcrmClient $ucrm,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<int,string|null> $accountNumbers candidate identities (IBAN, sender name) */
    public function learn(int $clientId, array $accountNumbers): void
    {
        try {
            $client = $this->ucrm->get('clients/' . $clientId);
            $existing = [];
            $payload = [];
            foreach (is_array($client['bankAccounts'] ?? null) ? $client['bankAccounts'] : [] as $account) {
                if (! is_array($account)) {
                    continue;
                }
                if (isset($account['accountNumber'])) {
                    $existing[ClientMatcher::normalizeIban((string) $account['accountNumber'])] = true;
                }
                // Preserve verbatim (id, name, … and entries without accountNumber too) —
                // rebuilding as ['accountNumber' => ...] would drop other fields under
                // replace-semantics PATCH.
                $payload[] = $account;
            }

            $added = 0;
            foreach ($accountNumbers as $number) {
                $number = trim((string) $number);
                $key = ClientMatcher::normalizeIban($number);
                if ($number === '' || isset($existing[$key])) {
                    continue;
                }
                $existing[$key] = true;
                $payload[] = ['accountNumber' => $number];
                $added++;
            }
            if ($added === 0) {
                return;
            }

            $this->ucrm->patch('clients/' . $clientId, ['bankAccounts' => $payload]);
            $this->logger->info(sprintf('Learner: client %d gained %d sender identit%s.', $clientId, $added, $added === 1 ? 'y' : 'ies'));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Learner: could not update client %d: %s', $clientId, $e->getMessage()));
        }
    }
}
