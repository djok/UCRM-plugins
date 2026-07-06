<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Status;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Status\MonthWindow;

final class MonthWindowTest extends TestCase
{
    private const NOW = 1783333800; // 2026-07-06T10:30:00Z

    public function testPastMonthProducesFullWindow(): void
    {
        $window = MonthWindow::fromQuery('2026-06', self::NOW);

        self::assertSame('2026-06', $window->ym);
        self::assertSame('2026-06-01T00:00:00Z', $window->fromIso);
        self::assertSame('2026-07-01T00:00:00Z', $window->toIso);
        self::assertSame('2026-06-01', $window->fromDate);
        self::assertSame('2026-06-30', $window->toDate);
    }

    public function testCurrentMonthClampsUpperBoundToNow(): void
    {
        $window = MonthWindow::fromQuery('2026-07', self::NOW);

        self::assertSame('2026-07-06T10:30:00Z', $window->toIso);
        self::assertSame('2026-07-31', $window->toDate);
    }

    public function testInvalidAbsentAndFutureMonthsFallBackToCurrent(): void
    {
        foreach ([null, '', 'junk', '2026-13', '2026-1', '2027-01'] as $input) {
            self::assertSame('2026-07', MonthWindow::fromQuery($input, self::NOW)->ym, var_export($input, true));
        }
    }

    public function testLeapFebruary(): void
    {
        self::assertSame('2028-02-29', MonthWindow::fromQuery('2028-02', strtotime('2028-06-01T00:00:00Z'))->toDate);
    }

    public function testLastMonthsCrossesYearBoundaryNewestFirst(): void
    {
        $months = MonthWindow::lastMonths(3, strtotime('2026-01-15T00:00:00Z'));

        self::assertSame(['2026-01', '2025-12', '2025-11'], $months);
    }
}
