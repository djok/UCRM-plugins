<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Matching;

use RevolutPaymentsImport\Ucrm\UcrmClient;

final class ClientMatcher implements ClientRepository
{
    public function __construct(private readonly UcrmClient $ucrm)
    {
    }

    public function findClientByIban(string $iban): ?array
    {
        $needle = self::normalizeIban($iban);
        if ($needle === '') {
            return null;
        }

        return self::matchInList($needle, $this->ucrm->get('clients'));
    }

    /**
     * @param array<int,array<mixed>> $clients
     * @return array<mixed>|null
     */
    public static function matchInList(string $normalizedIban, array $clients): ?array
    {
        foreach ($clients as $client) {
            if (! isset($client['bankAccounts']) || ! is_array($client['bankAccounts'])) {
                continue;
            }
            foreach ($client['bankAccounts'] as $account) {
                $number = $account['accountNumber'] ?? null;
                if (is_string($number) && self::normalizeIban($number) === $normalizedIban) {
                    return $client;
                }
            }
        }

        return null;
    }

    public static function normalizeIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }
}
