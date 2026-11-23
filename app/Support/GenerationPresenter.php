<?php

namespace App\Support;

use App\Enums\GenerationStatus;
use App\Enums\TransactionDirection;
use App\Models\Generation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GenerationPresenter
{
    /**
     * Compact payload for history cards and dashboard rows.
     *
     * @return array<string, mixed>
     */
    public function summary(Generation $generation): array
    {
        $status = $generation->status->status();

        return [
            'id' => $generation->id,
            'status' => $status->value,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_image_count' => is_array($generation->reference_images) ? count($generation->reference_images) : 0,
            'estimated_max_display' => Money::formatDisplay($generation->estimated_max_user_cost),
            'actual_user_cost_display' => $generation->actual_user_cost !== null
                ? Money::formatDisplay($generation->actual_user_cost)
                : null,
            'is_free' => $status === GenerationStatus::Failed,
            'image_url' => $this->safeImageUrl($generation->image_url),
            'image_expired' => $generation->image_url_expires_at !== null && $generation->image_url_expires_at->isPast(),
            'error_message' => $generation->error_message,
            'is_pending' => in_array($status, [GenerationStatus::Queued, GenerationStatus::Processing], true),
            'created_at' => $generation->created_at?->toIso8601String(),
            'created_at_human' => $generation->created_at?->diffForHumans(),
            'show_url' => route('generations.show', $generation),
            'refresh_url' => route('generations.refresh', $generation),
        ];
    }

    /**
     * Full payload for the detail page, including token and rate breakdowns.
     *
     * @return array<string, mixed>
     */
    public function detail(Generation $generation): array
    {
        return $this->summary($generation) + [
            'estimated_min_display' => Money::formatDisplay($generation->estimated_min_user_cost),
            'actual_provider_cost_display' => $generation->actual_provider_cost !== null
                ? Money::formatDisplay($generation->actual_provider_cost)
                : null,
            'wallet_net_debit_display' => Money::formatDisplay($this->walletNetDebit($generation)),
            'provider_id' => $generation->provider_id,
            'reference_images' => $generation->reference_images ?? [],
            'estimated_tokens' => $generation->estimated_tokens,
            'actual_usage' => $generation->actual_usage,
            'rates_snapshot' => $generation->rates_snapshot,
            'provider_created_at' => $generation->provider_created_at?->toIso8601String(),
            'started_at' => $generation->started_at?->toIso8601String(),
            'completed_at' => $generation->completed_at?->toIso8601String(),
            'error_code' => $generation->error_code,
        ];
    }

    /**
     * Per-status generation counts for filter tabs and dashboard cards.
     *
     * @return array{total: int, queued: int, processing: int, succeeded: int, failed: int}
     */
    public function statusCounts(User $user): array
    {
        // Direct DB query — counts don't need hydrated models.
        $counts = DB::table('generations')
            ->where('user_id', $user->id)
            ->select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'queued' => (int) ($counts[GenerationStatus::Queued->value] ?? 0),
            'processing' => (int) ($counts[GenerationStatus::Processing->value] ?? 0),
            'succeeded' => (int) ($counts[GenerationStatus::Succeeded->value] ?? 0),
            'failed' => (int) ($counts[GenerationStatus::Failed->value] ?? 0),
        ];
    }

    /**
     * Actual wallet movement for a generation: holds + captures − releases.
     * Distinct from actual_user_cost, which is the billed cost and can exceed
     * what the wallet was debited (unreserved or partially covered shortfalls).
     */
    private function walletNetDebit(Generation $generation): int
    {
        return $generation->transactions()
            ->select(['direction', 'amount'])
            ->get()
            ->reduce(
                fn (int $net, Transaction $transaction): int => $transaction->direction === TransactionDirection::Debit
                    ? $net + $transaction->amount
                    : $net - $transaction->amount,
                0,
            );
    }

    /**
     * Provider-returned image URLs are untrusted: only allow HTTPS before render.
     */
    private function safeImageUrl(?string $url): ?string
    {
        return $url !== null && Str::startsWith($url, 'https://') ? $url : null;
    }
}
