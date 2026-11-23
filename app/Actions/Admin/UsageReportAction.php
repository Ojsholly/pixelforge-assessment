<?php

namespace App\Actions\Admin;

use App\Data\Pixelforge\UsageDayData;
use App\Data\Pixelforge\UsageReportData;
use App\Enums\GenerationStatus;
use App\Enums\TransactionDirection;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the admin usage/profit report: local ledger truth vs the provider's
 * own GET /v1/usage billing. Pixelforge bills a generation on the UTC day it
 * *completed*, so local rows group by UTC completed_at date — not created_at —
 * and only succeeded generations carry cost.
 *
 * user_spend is the billed charge (actual_user_cost); collected is the net
 * wallet debit (holds + captures − releases), which can lag billed when a
 * wallet-capped shortfall left part of the charge uncollected.
 */
final readonly class UsageReportAction
{
    /**
     * @return array{
     *     days: list<array{date: string, generations: int, user_spend: int, collected: int, provider_cost: int, reported_cost: int, delta: int}>,
     *     users: list<array{name: string, email: string, generations: int, user_spend: int, collected: int, provider_cost: int}>,
     *     totals: array{generations: int, user_spend: int, collected: int, provider_cost: int, reported_cost: int, delta: int},
     *     provider_generations: int,
     * }
     */
    public function execute(CarbonImmutable $start, CarbonImmutable $end, UsageReportData $providerUsage): array
    {
        $netDebit = $this->netDebitSubquery($start, $end);

        /** @var Collection<string, object{day: string, generations: int|string, user_spend: int|string, collected: int|string, provider_cost: int|string}> $localDays */
        $localDays = DB::table('generations')
            ->leftJoinSub($netDebit, 'net', 'net.transactionable_id', '=', 'generations.id')
            ->where('status', GenerationStatus::Succeeded->value)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end->endOfDay()])
            ->selectRaw('date(completed_at) as day, count(*) as generations, coalesce(sum(actual_user_cost), 0) as user_spend, coalesce(sum(coalesce(net.net, 0)), 0) as collected, coalesce(sum(actual_provider_cost), 0) as provider_cost')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        /** @var Collection<int, object{name: string, email: string, generations: int|string, user_spend: int|string, collected: int|string, provider_cost: int|string}> $perUser */
        $perUser = DB::table('generations')
            ->leftJoinSub($netDebit, 'net', 'net.transactionable_id', '=', 'generations.id')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->whereNotNull('generations.completed_at')
            ->whereBetween('generations.completed_at', [$start, $end->endOfDay()])
            ->selectRaw('users.name, users.email, count(*) as generations, coalesce(sum(generations.actual_user_cost), 0) as user_spend, coalesce(sum(coalesce(net.net, 0)), 0) as collected, coalesce(sum(generations.actual_provider_cost), 0) as provider_cost')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('user_spend')
            ->get();

        // Generations whose owner was deleted keep billing history (user_id
        // nulled) — surface them as an aggregate pseudo-user.
        /** @var object{generations: int|string, user_spend: int|string, collected: int|string, provider_cost: int|string}|null $orphaned */
        $orphaned = DB::table('generations')
            ->leftJoinSub($netDebit, 'net', 'net.transactionable_id', '=', 'generations.id')
            ->whereNull('generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->whereNotNull('generations.completed_at')
            ->whereBetween('generations.completed_at', [$start, $end->endOfDay()])
            ->selectRaw('count(*) as generations, coalesce(sum(generations.actual_user_cost), 0) as user_spend, coalesce(sum(coalesce(net.net, 0)), 0) as collected, coalesce(sum(generations.actual_provider_cost), 0) as provider_cost')
            ->first();

        $users = $perUser->map(fn (object $row): array => [
            'name' => $row->name,
            'email' => $row->email,
            'generations' => (int) $row->generations,
            'user_spend' => (int) $row->user_spend,
            'collected' => (int) $row->collected,
            'provider_cost' => (int) $row->provider_cost,
        ]);

        if ($orphaned !== null && (int) $orphaned->generations > 0) {
            $users->push([
                'name' => 'Deleted users',
                'email' => '—',
                'generations' => (int) $orphaned->generations,
                'user_spend' => (int) $orphaned->user_spend,
                'collected' => (int) $orphaned->collected,
                'provider_cost' => (int) $orphaned->provider_cost,
            ]);
        }

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
                'collected' => (int) ($local->collected ?? 0),
                'provider_cost' => $providerCost,
                'reported_cost' => $usageDay instanceof UsageDayData ? $usageDay->costNanodollars : 0,
                'delta' => $providerCost - ($usageDay instanceof UsageDayData ? $usageDay->costNanodollars : 0),
            ];
        })->all());

        return [
            'days' => $days,
            'users' => array_values($users->all()),
            'totals' => [
                'generations' => array_sum(array_column($days, 'generations')),
                'user_spend' => array_sum(array_column($days, 'user_spend')),
                'collected' => array_sum(array_column($days, 'collected')),
                'provider_cost' => array_sum(array_column($days, 'provider_cost')),
                'reported_cost' => array_sum(array_column($days, 'reported_cost')),
                'delta' => array_sum(array_column($days, 'delta')),
            ],
            'provider_generations' => $providerUsage->totalGenerations,
        ];
    }

    /**
     * Net wallet debit per in-range succeeded generation: debits (holds +
     * captures) minus credits (releases), matching GenerationPresenter's
     * wallet_net_debit.
     */
    private function netDebitSubquery(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return DB::table('transactions')
            ->join('generations', function (JoinClause $join): void {
                $join->on('generations.id', '=', 'transactions.transactionable_id')
                    ->where('transactions.transactionable_type', '=', (new Generation)->getMorphClass());
            })
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->whereBetween('generations.completed_at', [$start, $end->endOfDay()])
            ->select('transactions.transactionable_id')
            ->selectRaw('sum(case when direction = ? then -amount else amount end) as net', [TransactionDirection::Credit->value])
            ->groupBy('transactions.transactionable_id');
    }
}
