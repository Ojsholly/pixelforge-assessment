<?php

namespace App\Filters\Transactions;

use App\Enums\TransactionDirection;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `?direction=` filter for the wallet ledger index.
 */
final readonly class DirectionFilter
{
    public function __construct(
        private ?TransactionDirection $direction,
    ) {}

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function handle(Builder $query, Closure $next): Builder
    {
        if ($this->direction instanceof \App\Enums\TransactionDirection) {
            $query->where('direction', $this->direction->value);
        }

        return $next($query);
    }
}
