<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Matching;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Matching\ClientMatcher;
use RevolutPaymentsImport\Ucrm\UcrmClient;

final class ClientMatcherTest extends TestCase
{
    /** @return array<int,array<mixed>> */
    private function clients(): array
    {
        return [
            ['id' => 10, 'bankAccounts' => [['accountNumber' => 'BG80 BNBG 9661 1020 3456 78']]],
            ['id' => 20, 'bankAccounts' => [['accountNumber' => 'DE89370400440532013000']]],
            ['id' => 30], // no bank accounts
        ];
    }

    private function ucrm(array $clients): UcrmClient
    {
        return new class($clients) implements UcrmClient {
            public function __construct(private array $clients)
            {
            }

            public function get(string $endpoint, array $params = []): array
            {
                return $endpoint === 'clients' ? $this->clients : [];
            }

            public function post(string $endpoint, array $data): array
            {
                return [];
            }
        };
    }

    public function testNormalizeIbanStripsSpacesAndCase(): void
    {
        self::assertSame('BG80BNBG96611020345678', ClientMatcher::normalizeIban('bg80 bnbg 9661 1020 3456 78'));
    }

    public function testMatchesIgnoringSpacesAndCase(): void
    {
        $matcher = new ClientMatcher($this->ucrm($this->clients()));

        $client = $matcher->findClientByIban('BG80BNBG96611020345678');

        self::assertNotNull($client);
        self::assertSame(10, $client['id']);
    }

    public function testReturnsNullWhenNoMatch(): void
    {
        $matcher = new ClientMatcher($this->ucrm($this->clients()));

        self::assertNull($matcher->findClientByIban('FR7630006000011234567890189'));
    }

    public function testReturnsNullForEmptyIban(): void
    {
        $matcher = new ClientMatcher($this->ucrm($this->clients()));

        self::assertNull($matcher->findClientByIban(''));
    }
}
