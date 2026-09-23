<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Revolut;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Revolut\TransactionShape;

final class TransactionShapeTest extends TestCase
{
    public function testSinglePositiveLegIsACustomerTransfer(): void
    {
        self::assertFalse(TransactionShape::isInternalTransfer(['legs' => [
            ['account_id' => 'acc-main', 'amount' => 12.44, 'fee' => 0.1],
        ]]));
    }

    public function testNegativeLegMeansMoneyLeftAnOwnAccount(): void
    {
        self::assertTrue(TransactionShape::isInternalTransfer(['legs' => [
            ['account_id' => 'acc-hold', 'amount' => -288.87, 'description' => 'Release'],
            ['account_id' => 'acc-main', 'amount' => 288.87],
        ]]));
    }

    public function testTwoLegsAreInternalEvenIfBothPositive(): void
    {
        // Revolut's own spec: 2 legs only for transactions between your accounts;
        // its internal_transfer example even shows both legs positive.
        self::assertTrue(TransactionShape::isInternalTransfer(['legs' => [
            ['account_id' => 'acc-deposit', 'amount' => 100, 'description' => 'To GBP Deposit pocket'],
            ['account_id' => 'acc-regular', 'amount' => 100, 'description' => 'From GBP Regular pocket'],
        ]]));
    }

    public function testMissingLegsIsNotInternal(): void
    {
        self::assertFalse(TransactionShape::isInternalTransfer([]));
        self::assertFalse(TransactionShape::isInternalTransfer(['legs' => 'garbage']));
    }
}
