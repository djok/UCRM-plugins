<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Status;

/**
 * UTC time window of one calendar month for the status page. The upper bound
 * is clamped to "now" so the current month queries only elapsed time.
 */
final class MonthWindow
{
    private function __construct(
        public readonly string $ym,
        public readonly string $fromIso,
        public readonly string $toIso,
        public readonly string $fromDate,
        public readonly string $toDate,
    ) {
    }

    public static function fromQuery(?string $month, int $nowTs): self
    {
        $currentYm = gmdate('Y-m', $nowTs);
        $ym = is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? $month
            : $currentYm;
        if ($ym > $currentYm) {
            $ym = $currentYm;
        }

        $start = new \DateTimeImmutable($ym . '-01T00:00:00Z');
        $nextMonth = $start->modify('first day of next month');
        $toTs = min($nextMonth->getTimestamp(), $nowTs);

        return new self(
            $ym,
            $start->format('Y-m-d\TH:i:s\Z'),
            gmdate('Y-m-d\TH:i:s\Z', $toTs),
            $start->format('Y-m-d'),
            $nextMonth->modify('-1 day')->format('Y-m-d'),
        );
    }

    /** @return list<string> 'YYYY-MM', newest first, including the current month */
    public static function lastMonths(int $count, int $nowTs): array
    {
        $months = [];
        $cursor = new \DateTimeImmutable(gmdate('Y-m', $nowTs) . '-01T00:00:00Z');
        for ($i = 0; $i < $count; $i++) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('-1 month');
        }

        return $months;
    }
}
