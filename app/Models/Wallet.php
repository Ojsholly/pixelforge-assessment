<?php

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $balance
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string $balance_usd
 * @property-read string $formatted_balance
 * @property-read User $user
 */
#[Fillable(['user_id'])]
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
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
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function balanceUsd(): Attribute
    {
        return Attribute::get(fn (): string => Money::toUsdString($this->balance));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function formattedBalance(): Attribute
    {
        return Attribute::get(fn (): string => Money::formatDisplay($this->balance));
    }
}
