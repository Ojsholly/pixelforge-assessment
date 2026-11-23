<?php

namespace App\Data\Pixelforge;

use App\Support\Money;
use InvalidArgumentException;

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
        foreach (['start_date', 'end_date'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key])) {
                throw new InvalidArgumentException("Malformed usage report payload: missing or invalid [{$key}].");
            }
        }

        // data and total are required: silently defaulting them would turn an
        // incomplete provider response into a false "zero billing" report.
        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            throw new InvalidArgumentException('Malformed usage report payload: missing or invalid [data].');
        }

        $rawDays = $payload['data'];

        foreach ($rawDays as $rawDay) {
            if (! is_array($rawDay)) {
                throw new InvalidArgumentException('Malformed usage report payload: [data] entries must be objects.');
            }
        }

        if (! isset($payload['total']) || ! is_array($payload['total'])) {
            throw new InvalidArgumentException('Malformed usage report payload: missing or invalid [total].');
        }

        $total = $payload['total'];

        if (! isset($total['generations']) || ! is_numeric($total['generations'])
            || ! isset($total['cost_usd']) || ! is_string($total['cost_usd'])) {
            throw new InvalidArgumentException('Malformed usage report payload: missing or invalid [total] fields.');
        }

        $totalCostUsd = $total['cost_usd'];

        return new self(
            startDate: (string) $payload['start_date'],
            endDate: (string) $payload['end_date'],
            days: array_values(array_map(UsageDayData::fromArray(...), $rawDays)),
            totalGenerations: (int) $total['generations'],
            totalCostUsd: $totalCostUsd,
            totalCostNanodollars: Money::fromUsdString($totalCostUsd),
        );
    }
}
