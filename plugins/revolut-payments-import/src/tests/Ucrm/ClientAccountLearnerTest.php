<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Ucrm;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\ClientAccountLearner;
use RevolutPaymentsImport\Ucrm\UcrmClient;

final class ClientAccountLearnerTest extends TestCase
{
    /** @var list<string> */
    private array $logLines = [];

    private function logger(): Logger
    {
        return new Logger(function (string $line): void {
            $this->logLines[] = $line;
        });
    }

    /** @param array<string,mixed> $client */
    private function ucrm(array $client): UcrmClient
    {
        return new class($client) implements UcrmClient {
            /** @var array<int,array{endpoint:string,data:array<string,mixed>}> */
            public array $patched = [];

            /** @param array<string,mixed> $client */
            public function __construct(private readonly array $client)
            {
            }

            public function get(string $endpoint, array $params = []): array
            {
                return $this->client;
            }

            public function post(string $endpoint, array $data): array
            {
                return [];
            }

            public function patch(string $endpoint, array $data): array
            {
                $this->patched[] = ['endpoint' => $endpoint, 'data' => $data];

                return [];
            }
        };
    }

    public function testAppendsOnlyMissingEntriesPreservingExisting(): void
    {
        $ucrm = $this->ucrm(['id' => 42, 'bankAccounts' => [['id' => 1, 'accountNumber' => 'BG47UNCR70001521149247']]]);

        (new ClientAccountLearner($ucrm, $this->logger()))
            ->learn(42, ['bg47 uncr 7000 1521 1492 47', 'Hadzhiradevi Ood']);

        self::assertCount(1, $ucrm->patched);
        self::assertSame('clients/42', $ucrm->patched[0]['endpoint']);
        $accounts = $ucrm->patched[0]['data']['bankAccounts'];
        self::assertSame('BG47UNCR70001521149247', $accounts[0]['accountNumber']);
        self::assertSame('Hadzhiradevi Ood', $accounts[1]['accountNumber']);
        self::assertCount(2, $accounts);
    }

    public function testExistingEntriesArePassedThroughVerbatim(): void
    {
        $ucrm = $this->ucrm([
            'id' => 42,
            'bankAccounts' => [
                ['id' => 5, 'accountNumber' => 'BG47UNCR70001521149247', 'name' => 'Main'],
                ['id' => 9],
            ],
        ]);

        (new ClientAccountLearner($ucrm, $this->logger()))
            ->learn(42, ['Hadzhiradevi Ood']);

        self::assertCount(1, $ucrm->patched);
        $accounts = $ucrm->patched[0]['data']['bankAccounts'];
        self::assertSame(['id' => 5, 'accountNumber' => 'BG47UNCR70001521149247', 'name' => 'Main'], $accounts[0]);
        self::assertSame(['id' => 9], $accounts[1]);
        self::assertSame(['accountNumber' => 'Hadzhiradevi Ood'], $accounts[2]);
        self::assertCount(3, $accounts);
    }

    public function testNoPatchWhenEverythingAlreadyKnown(): void
    {
        $ucrm = $this->ucrm(['id' => 42, 'bankAccounts' => [['accountNumber' => 'HADZHIRADEVIOOD']]]);

        (new ClientAccountLearner($ucrm, $this->logger()))->learn(42, ['Hadzhiradevi Ood', '', null]);

        self::assertSame([], $ucrm->patched);
    }

    public function testApiFailureIsLoggedNotThrown(): void
    {
        $ucrm = new class implements UcrmClient {
            public function get(string $endpoint, array $params = []): array
            {
                return ['id' => 42, 'bankAccounts' => []];
            }

            public function post(string $endpoint, array $data): array
            {
                return [];
            }

            public function patch(string $endpoint, array $data): array
            {
                throw new \RuntimeException('boom');
            }
        };

        (new ClientAccountLearner($ucrm, $this->logger()))->learn(42, ['Somebody Ltd']);

        self::assertNotSame([], $this->logLines);
    }
}
