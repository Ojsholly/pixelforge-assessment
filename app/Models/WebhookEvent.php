<?php

namespace App\Models;

use App\Enums\WebhookEventType;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider_event_id
 * @property string $event_type
 * @property string|null $provider_generation_id
 * @property int $delivery_attempt
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'provider_event_id',
    'event_type',
    'provider_generation_id',
    'delivery_attempt',
    'payload',
    'processed_at',
])]
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory, MassPrunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_attempt' => 'integer',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function resolvedEventType(): ?WebhookEventType
    {
        return WebhookEventType::tryFrom($this->event_type);
    }

    /**
     * Payloads contain prompt text and image URLs; retain processed events only
     * long enough to serve their idempotency and audit purpose.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNotNull('processed_at')
            ->where('created_at', '<=', now()->subDays(30));
    }
}
