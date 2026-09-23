<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Ucrm;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Support\Logger;
use RevolutPaymentsImport\Ucrm\PaymentFinder;
use RevolutPaymentsImport\Ucrm\UcrmClient;

final class PaymentFinderTest extends TestCase
{
    /** @param list<array<string,mixed>> $payments */
    private function ucrm(array $payments): UcrmClient
    {
        return new class($payments) implements UcrmClient {
            /** @var array<string,scalar>|null */
            public ?array $lastParams = null;

            /** @param list<array<string,mixed>> $payments */
            public function __construct(private readonly array $payments)
            {
            }

            public function get(string $endpoint, array $params = []): array
            {
                $this->lastParams = $params;

                return $this->payments;
            }

            public function post(string $endpoint, array $data): array
            {
                return [];
            }

            public function patch(string $endpoint, array $data): array
            {
                return [];
            }
        };
    }

    public function testFindsByProviderPaymentId(): void
    {
        $payment = ['id' => 5, 'providerName' => 'Revolut', 'providerPaymentId' => 'tx-1', 'amount' => 999.0, 'note' => 'x', 'createdDate' => '2026-06-15T00:00:00+03:00'];

        $found = (new PaymentFinder($this->ucrm([$payment])))->findForStatementRow('tx-1', '2026-06-15', 8.86);

        self::assertSame(5, $found['id']);
    }

    public function testFallsBackToLegacyNoteAmountDateHeuristic(): void
    {
        $legacy = ['id' => 6, 'providerPaymentId' => null, 'note' => 'Revolut: someone', 'amount' => 8.86, 'createdDate' => '2026-06-15T10:00:00+03:00'];

        $found = (new PaymentFinder($this->ucrm([$legacy])))->findForStatementRow('tx-1', '2026-06-15', 8.86);

        self::assertSame(6, $found['id']);
    }

    public function testReturnsNullWhenNothingMatches(): void
    {
        $other = ['id' => 8, 'providerPaymentId' => null, 'note' => 'платено на каса', 'amount' => 8.86, 'createdDate' => '2026-06-15T10:00:00+03:00'];

        self::assertNull((new PaymentFinder($this->ucrm([$other])))->findForStatementRow('tx-1', '2026-06-15', 8.86));
    }

    public function testQueriesPaddedDateWindow(): void
    {
        $ucrm = $this->ucrm([]);
        (new PaymentFinder($ucrm))->findForStatementRow('tx-1', '2026-06-01', 1.0);

        self::assertSame('2026-05-31', $ucrm->lastParams['createdDateFrom']);
        self::assertSame('2026-06-02', $ucrm->lastParams['createdDateTo']);
    }

    public function testPagesBeyondTheFirst500Payments(): void
    {
        // Busy days can hold more than one page of payments; the target must still be found.
        $filler = [];
        for ($i = 0; $i < 500; $i++) {
            $filler[] = ['id' => 1000 + $i, 'providerPaymentId' => null, 'note' => 'other', 'amount' => 1.0, 'createdDate' => '2026-06-15T10:00:00+03:00'];
        }
        $target = ['id' => 7, 'providerPaymentId' => null, 'note' => 'Revolut: someone', 'amount' => 8.86, 'createdDate' => '2026-06-15T10:00:00+03:00'];
        $ucrm = new class($filler, [$target]) implements UcrmClient {
            public function __construct(private readonly array $page1, private readonly array $page2)
            {
            }

            public function get(string $endpoint, array $params = []): array
            {
                return ($params['offset'] ?? 0) === 0 ? $this->page1 : $this->page2;
            }

            public function post(string $endpoint, array $data): array
            {
                return [];
            }

            public function patch(string $endpoint, array $data): array
            {
                return [];
            }
        };

        $found = (new PaymentFinder($ucrm))->findForStatementRow('tx-1', '2026-06-15', 8.86);

        self::assertSame(7, $found['id'] ?? null);
    }

    public function testStampedPaymentForOtherTransactionIsNotHeuristicallyMatched(): void
    {
        $stampedForOther = [
            'id' => 20,
            'providerPaymentId' => 'tx-OTHER',
            'note' => 'Revolut: x',
            'amount' => 8.86,
            'createdDate' => '2026-06-15T10:00:00+03:00',
        ];

        $found = (new PaymentFinder($this->ucrm([$stampedForOther])))->findForStatementRow('tx-1', '2026-06-15', 8.86);

        self::assertNull($found);
    }

    public function testAmbiguousLegacyCandidatesReturnNullAndLog(): void
    {
        $legacyA = ['id' => 21, 'providerPaymentId' => null, 'note' => 'Revolut: someone', 'amount' => 8.86, 'createdDate' => '2026-06-15T10:00:00+03:00'];
        $legacyB = ['id' => 22, 'providerPaymentId' => '', 'note' => 'Revolut: someone else', 'amount' => 8.86, 'createdDate' => '2026-06-15T11:00:00+03:00'];

        /** @var list<string> $logLines */
        $logLines = [];
        $logger = new Logger(function (string $line) use (&$logLines): void {
            $logLines[] = $line;
        });

        $found = (new PaymentFinder($this->ucrm([$legacyA, $legacyB]), $logger))->findForStatementRow('tx-1', '2026-06-15', 8.86);

        self::assertNull($found);
        self::assertNotSame([], $logLines);
        self::assertStringContainsString('ambiguous', $logLines[0]);
    }
}
