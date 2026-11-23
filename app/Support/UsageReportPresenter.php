<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class UsageReportPresenter
{
    /**
     * Shape the aggregated report for the Inertia page. Every money figure is
     * emitted both as an exact 9-decimal USD string (provider truth, matches
     * the Pixelforge wire format) and a human display string, so reconciliation
     * never crosses a float. `uncollected` is billed spend the wallet never
     * paid (capped shortfalls) — kept separate so profit math stays honest.
     *
     * @param  array{
     *     days: list<array{date: string, generations: int, user_spend: int, collected: int, provider_cost: int, reported_cost: int, delta: int}>,
     *     users: list<array{name: string, email: string, generations: int, user_spend: int, collected: int, provider_cost: int}>,
     *     totals: array{generations: int, user_spend: int, collected: int, provider_cost: int, reported_cost: int, delta: int},
     *     provider_generations: int,
     * }  $report
     * @return array<string, mixed>
     */
    public function page(array $report, CarbonImmutable $start, CarbonImmutable $end, int $maxDays): array
    {
        return [
            'range' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'max_days' => $maxDays,
            ],
            'days' => array_map(fn (array $day): array => [
                'date' => $day['date'],
                'generations' => $day['generations'],
                ...$this->moneyPair('user_spend', $day['user_spend']),
                ...$this->moneyPair('collected', $day['collected']),
                ...$this->moneyPair('uncollected', $day['user_spend'] - $day['collected']),
                ...$this->moneyPair('provider_cost', $day['provider_cost']),
                ...$this->moneyPair('profit', $day['user_spend'] - $day['provider_cost']),
                ...$this->moneyPair('reported_cost', $day['reported_cost']),
                ...$this->moneyPair('delta', $day['delta']),
                'delta' => $day['delta'],
            ], $report['days']),
            'users' => array_map(fn (array $user): array => [
                'name' => $user['name'],
                'email' => $user['email'],
                'generations' => $user['generations'],
                ...$this->moneyPair('user_spend', $user['user_spend']),
                ...$this->moneyPair('collected', $user['collected']),
                ...$this->moneyPair('uncollected', $user['user_spend'] - $user['collected']),
                ...$this->moneyPair('provider_cost', $user['provider_cost']),
                ...$this->moneyPair('profit', $user['user_spend'] - $user['provider_cost']),
            ], $report['users']),
            'totals' => [
                'generations' => $report['totals']['generations'],
                'provider_generations' => $report['provider_generations'],
                ...$this->moneyPair('user_spend', $report['totals']['user_spend']),
                ...$this->moneyPair('collected', $report['totals']['collected']),
                ...$this->moneyPair('uncollected', $report['totals']['user_spend'] - $report['totals']['collected']),
                ...$this->moneyPair('provider_cost', $report['totals']['provider_cost']),
                ...$this->moneyPair('profit', $report['totals']['user_spend'] - $report['totals']['provider_cost']),
                ...$this->moneyPair('reported_cost', $report['totals']['reported_cost']),
                ...$this->moneyPair('delta', $report['totals']['delta']),
                'delta' => $report['totals']['delta'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function moneyPair(string $key, int $nanodollars): array
    {
        return [
            "{$key}_usd" => Money::toUsdString($nanodollars),
            "{$key}_display" => Money::formatDisplay($nanodollars),
        ];
    }
}
