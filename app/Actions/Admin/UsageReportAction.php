<?php

namespace App\Actions\Admin;

use App\Data\Pixelforge\UsageDayData;
use App\Data\Pixelforge\UsageReportData;
use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the admin usage/profit report: local ledger truth vs the provider's
 * own GET /v1/usage billing. Pixelforge bills a generation on the UTC day it
 * *completed*, so local rows group by UTC completed_at date — not created_at —
 * and only succeeded generations carry cost.
 */
final readonly class UsageReportAction
{
    /**
     * @return array{
     *     days: list<array{date: string, generations: int, user_spend: int, provider_cost: int, reported_cost: int, delta: int}>,
     *     users: list<array{name: string, email: string, generations: int, user_spend: int, provider_cost: int}>,
     *     totals: array{generations: int, user_spend: int, provider_cost: int, reported_cost: int, delta: int},
     *     provider_generations: int,
     * }
     */
    public function execute(CarbonImmutable $start, CarbonImmutable $end, UsageReportData $providerUsage): array
    {
        /** @var Collection<string, object{day: string, generations: int|string, user_spend: int|string, provider_cost: int|string}> $localDays */
        $localDays = DB::table('generations')
            ->where('status', GenerationStatus::Succeeded->value)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end->endOfDay()])
            ->selectRaw('date(completed_at) as day, count(*) as generations, coalesce(sum(actual_user_cost), 0) as user_spend, coalesce(sum(actual_provider_cost), 0) as provider_cost')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        /** @var Collection<int, object{name: string, email: string, generations: int|string, user_spend: int|string, provider_cost: int|string}> $perUser */
        $perUser = DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->whereNotNull('generations.completed_at')
            ->whereBetween('generations.completed_at', [$start, $end->endOfDay()])
            ->selectRaw('users.name, users.email, count(*) as generations, coalesce(sum(generations.actual_user_cost), 0) as user_spend, coalesce(sum(generations.actual_provider_cost), 0) as provider_cost')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('user_spend')
            ->get();

        $reportedByDay = collect($providerUsage->days)->keyBy('date');
        $dates = $localDays->keys()->merge($reportedByDay->keys())->unique()->sort()->values();

        $days = array_values($dates->map(function (string $date) use ($localDays, $reportedByDay): array {
            $local = $localDays->get($date);
            $providerCost = (int) ($local->provider_cost ?? 0);
            $usageDay = $reportedByDay->get($date);

            return [
                'date' => $date,
                'generations' => (int) ($local->generations ?? 0),
                'user_spend' => (int) ($local->user_spend ?? 0),
                'provider_cost' => $providerCost,
                'reported_cost' => $usageDay instanceof UsageDayData ? $usageDay->costNanodollars : 0,
                'delta' => $providerCost - ($usageDay instanceof UsageDayData ? $usageDay->costNanodollars : 0),
            ];
        })->all());

        return [
            'days' => $days,
            'users' => array_values($perUser->map(fn (object $row): array => [
                'name' => $row->name,
                'email' => $row->email,
                'generations' => (int) $row->generations,
                'user_spend' => (int) $row->user_spend,
                'provider_cost' => (int) $row->provider_cost,
            ])->all()),
            'totals' => [
                'generations' => array_sum(array_column($days, 'generations')),
                'user_spend' => array_sum(array_column($days, 'user_spend')),
                'provider_cost' => array_sum(array_column($days, 'provider_cost')),
                'reported_cost' => array_sum(array_column($days, 'reported_cost')),
                'delta' => array_sum(array_column($days, 'delta')),
            ],
            'provider_generations' => $providerUsage->totalGenerations,
        ];
    }
}
