<?php

namespace App\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Whitelisted `?sort=` filter: `?sort=amount` sorts ascending, `?sort=-amount`
 * descending. Unknown or missing values fall back to the default ordering.
 */
final readonly class Sort
{
    private string $column;

    /** @var 'asc'|'desc' */
    private string $direction;

    /**
     * @param  non-empty-array<int, string>  $allowedColumns
     * @param  'asc'|'desc'  $defaultDirection
     */
    public function __construct(
        private ?string $sort,
        private array $allowedColumns,
        private string $defaultColumn,
        private string $defaultDirection = 'desc',
    ) {
        $column = Str::ltrim((string) $this->sort, '-');

        if (in_array($column, $this->allowedColumns, true)) {
            $this->column = $column;
            $this->direction = Str::startsWith((string) $this->sort, '-') ? 'desc' : 'asc';
        } else {
            $this->column = $this->defaultColumn;
            $this->direction = $this->defaultDirection;
        }
    }

    /**
     * Normalized current sort (`amount` ascending, `-amount` descending).
     */
    public function current(): string
    {
        return ($this->direction === 'desc' ? '-' : '').$this->column;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function handle(Builder $query, Closure $next): Builder
    {
        return $next($query->orderBy($this->column, $this->direction));
    }
}
