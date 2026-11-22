<?php

namespace App\Models;

use App\Enums\WebhookEventType;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider_event_id
 * @property string $event_type
 * @property string|null $provider_generation_id
 * @property int $delivery_attempt
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
    use HasFactory;

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
}
