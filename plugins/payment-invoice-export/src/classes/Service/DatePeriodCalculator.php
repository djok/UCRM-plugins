<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

class DatePeriodCalculator
{
    /**
     * Calculate start and end dates for predefined periods.
     *
     * @param string $period One of: current_month, previous_month,
     *                       current_quarter, previous_quarter,
     *                       current_year, previous_year
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [start, end]
     */
    public function calculatePeriod(string $period): array
    {
        $now = new DateTimeImmutable();

        switch ($period) {
            case 'current_month':
                $start = $now->modify('first day of this month')->setTime(0, 0, 0);
                $end = $now->modify('last day of this month')->setTime(23, 59, 59);
                break;

            case 'previous_month':
                $start = $now->modify('first day of last month')->setTime(0, 0, 0);
                $end = $now->modify('last day of last month')->setTime(23, 59, 59);
                break;

            case 'current_quarter':
                $currentMonth = (int) $now->format('n');
                $quarterStartMonth = (int) (floor(($currentMonth - 1) / 3) * 3 + 1);
                $start = $now->setDate((int) $now->format('Y'), $quarterStartMonth, 1)
                             ->setTime(0, 0, 0);
                $end = $start->modify('+3 months -1 day')->setTime(23, 59, 59);
                break;

            case 'previous_quarter':
                $currentMonth = (int) $now->format('n');
                $quarterStartMonth = (int) (floor(($currentMonth - 1) / 3) * 3 + 1);
                $currentQuarterStart = $now->setDate((int) $now->format('Y'), $quarterStartMonth, 1);
                $start = $currentQuarterStart->modify('-3 months')->setTime(0, 0, 0);
                $end = $currentQuarterStart->modify('-1 day')->setTime(23, 59, 59);
                break;

            case 'current_year':
                $start = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
                $end = $now->setDate((int) $now->format('Y'), 12, 31)->setTime(23, 59, 59);
                break;

            case 'previous_year':
                $prevYear = (int) $now->format('Y') - 1;
                $start = $now->setDate($prevYear, 1, 1)->setTime(0, 0, 0);
                $end = $now->setDate($prevYear, 12, 31)->setTime(23, 59, 59);
                break;

            default:
                // Default to current month
                $start = $now->modify('first day of this month')->setTime(0, 0, 0);
                $end = $now->modify('last day of this month')->setTime(23, 59, 59);
        }

        return [$start, $end];
    }

    /**
     * Parse custom date range from form input.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [start, end]
     */
    public function getCustomRange(?string $dateFrom, ?string $dateTo): array
    {
        $now = new DateTimeImmutable();

        if ($dateFrom) {
            $start = new DateTimeImmutable($dateFrom);
        } else {
            $start = $now->modify('-30 days');
        }

        if ($dateTo) {
            $end = new DateTimeImmutable($dateTo);
        } else {
            $end = $now;
        }

        return [$start->setTime(0, 0, 0), $end->setTime(23, 59, 59)];
    }
}
