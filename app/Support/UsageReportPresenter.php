<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class UsageReportPresenter
{
    /**
     * Shape the aggregated report for the Inertia page. Every money figure is
     * emitted both as an exact 9-decimal USD string (provider truth, matches
     * the Pixelforge wire format) and a human display string, so reconciliation
     * never crosses a float.
     *
     * @param  array{
     *     days: list<array{date: string, generations: int, user_spend: int, provider_cost: int, reported_cost: int, delta: int}>,
     *     users: list<array{name: string, email: string, generations: int, user_spend: int, provider_cost: int}>,
     *     totals: array{generations: int, user_spend: int, provider_cost: int, reported_cost: int, delta: int},
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
                'user_spend_usd' => Money::toUsdString($day['user_spend']),
                'user_spend_display' => Money::formatDisplay($day['user_spend']),
                'provider_cost_usd' => Money::toUsdString($day['provider_cost']),
                'provider_cost_display' => Money::formatDisplay($day['provider_cost']),
                'profit_usd' => Money::toUsdString($day['user_spend'] - $day['provider_cost']),
                'profit_display' => Money::formatDisplay($day['user_spend'] - $day['provider_cost']),
                'reported_cost_usd' => Money::toUsdString($day['reported_cost']),
                'reported_cost_display' => Money::formatDisplay($day['reported_cost']),
                'delta_usd' => Money::toUsdString($day['delta']),
                'delta_display' => Money::formatDisplay($day['delta']),
                'delta' => $day['delta'],
            ], $report['days']),
            'users' => array_map(fn (array $user): array => [
                'name' => $user['name'],
                'email' => $user['email'],
                'generations' => $user['generations'],
                'user_spend_usd' => Money::toUsdString($user['user_spend']),
                'user_spend_display' => Money::formatDisplay($user['user_spend']),
                'provider_cost_usd' => Money::toUsdString($user['provider_cost']),
                'provider_cost_display' => Money::formatDisplay($user['provider_cost']),
                'profit_usd' => Money::toUsdString($user['user_spend'] - $user['provider_cost']),
                'profit_display' => Money::formatDisplay($user['user_spend'] - $user['provider_cost']),
            ], $report['users']),
            'totals' => [
                'generations' => $report['totals']['generations'],
                'provider_generations' => $report['provider_generations'],
                'user_spend_usd' => Money::toUsdString($report['totals']['user_spend']),
                'user_spend_display' => Money::formatDisplay($report['totals']['user_spend']),
                'provider_cost_usd' => Money::toUsdString($report['totals']['provider_cost']),
                'provider_cost_display' => Money::formatDisplay($report['totals']['provider_cost']),
                'profit_usd' => Money::toUsdString($report['totals']['user_spend'] - $report['totals']['provider_cost']),
                'profit_display' => Money::formatDisplay($report['totals']['user_spend'] - $report['totals']['provider_cost']),
                'reported_cost_usd' => Money::toUsdString($report['totals']['reported_cost']),
                'reported_cost_display' => Money::formatDisplay($report['totals']['reported_cost']),
                'delta_usd' => Money::toUsdString($report['totals']['delta']),
                'delta_display' => Money::formatDisplay($report['totals']['delta']),
                'delta' => $report['totals']['delta'],
            ],
        ];
    }
}
