<?php

namespace App\Filters\Generations;

use App\Enums\GenerationStatus;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `?status=` filter for the generation history index.
 */
final readonly class StatusFilter
{
    public function __construct(
        private ?GenerationStatus $status,
    ) {}

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function handle(Builder $query, Closure $next): Builder
    {
        if ($this->status instanceof \App\Enums\GenerationStatus) {
            $query->where('status', $this->status->value);
        }

        return $next($query);
    }
}
