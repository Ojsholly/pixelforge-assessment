<?php

namespace App\Models;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\States\Generation\GenerationState;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $provider_id
 * @property string $idempotency_key
 * @property GenerationState $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string>|null $reference_images
 * @property array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string} $rates_snapshot
 * @property array<string, int> $estimated_tokens
 * @property int $estimated_min_user_cost
 * @property int $estimated_max_user_cost
 * @property int $reserved_amount
 * @property array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}|null $actual_usage
 * @property int|null $actual_provider_cost
 * @property int|null $actual_user_cost
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $provider_created_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'provider_id',
    'idempotency_key',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'rates_snapshot',
    'estimated_tokens',
    'estimated_min_user_cost',
    'estimated_max_user_cost',
    'reserved_amount',
    'actual_usage',
    'actual_provider_cost',
    'actual_user_cost',
    'image_url',
    'image_url_expires_at',
    'error_code',
    'error_message',
    'provider_created_at',
    'started_at',
    'completed_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasStates;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationState::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'rates_snapshot' => 'array',
            'estimated_tokens' => 'array',
            'estimated_min_user_cost' => 'integer',
            'estimated_max_user_cost' => 'integer',
            'reserved_amount' => 'integer',
            'actual_usage' => 'array',
            'actual_provider_cost' => 'integer',
            'actual_user_cost' => 'integer',
            'image_url_expires_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphMany<Transaction, $this>
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }
}
