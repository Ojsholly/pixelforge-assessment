<?php

namespace App\Data\Pixelforge;

use App\Support\Money;

final readonly class UsageReportData
{
    /**
     * @param  list<UsageDayData>  $days
     */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public array $days,
        public int $totalGenerations,
        public string $totalCostUsd,
        public int $totalCostNanodollars,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var list<array<string, int|string>> $rawDays */
        $rawDays = $payload['data'] ?? [];
        /** @var array<string, int|string> $total */
        $total = $payload['total'] ?? [];
        $totalCostUsd = (string) ($total['cost_usd'] ?? '0.000000000');

        return new self(
            startDate: (string) $payload['start_date'],
            endDate: (string) $payload['end_date'],
            days: array_map(UsageDayData::fromArray(...), $rawDays),
            totalGenerations: (int) ($total['generations'] ?? 0),
            totalCostUsd: $totalCostUsd,
            totalCostNanodollars: Money::fromUsdString($totalCostUsd),
        );
    }
}
