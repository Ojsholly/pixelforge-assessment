<?php

namespace App\Filters\Transactions;

use App\Enums\TransactionType;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `?type=` filter for the wallet ledger index.
 */
final readonly class TypeFilter
{
    public function __construct(
        private ?TransactionType $type,
    ) {}

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function handle(Builder $query, Closure $next): Builder
    {
        if ($this->type instanceof TransactionType) {
            $query->where('type', $this->type->value);
        }

        return $next($query);
    }
}
