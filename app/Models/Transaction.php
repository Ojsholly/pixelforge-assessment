<?php

namespace App\Models;

use App\Enums\TransactionDirection;
use App\Enums\TransactionType;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $wallet_id
 * @property string|null $transactionable_type
 * @property int|null $transactionable_id
 * @property TransactionDirection $direction
 * @property TransactionType $type
 * @property int $amount
 * @property int $balance_after
 * @property string $description
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Wallet $wallet
 * @property-read Model|null $transactionable
 */
#[Fillable([
    'wallet_id',
    'transactionable_type',
    'transactionable_id',
    'direction',
    'type',
    'amount',
    'balance_after',
    'description',
    'metadata',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => TransactionDirection::class,
            'type' => TransactionType::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function transactionable(): MorphTo
    {
        return $this->morphTo();
    }
}
