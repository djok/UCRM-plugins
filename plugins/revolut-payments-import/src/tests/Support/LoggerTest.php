<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Support;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Support\Logger;

final class LoggerTest extends TestCase
{
    public function testRedactsTokens(): void
    {
        $lines = [];
        $logger = new Logger(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->info('using token oa_prod_rPo9OmbMAuguhQffR6RLR4nvmzpx4NJ and secret wsk_abcd1234efgh');

        self::assertStringContainsString('oa_prod_rPo9***', $lines[0]);
        self::assertStringContainsString('wsk_abcd***', $lines[0]);
        self::assertStringNotContainsString('OmbMAuguhQ', $lines[0]);
        self::assertStringContainsString('INFO', $lines[0]);
    }
}
