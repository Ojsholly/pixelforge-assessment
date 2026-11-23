<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pipeline\Pipeline;

/**
 * Runs an Eloquent query through the controller's filter pipeline. Each
 * filter class handles one concern (e.g. a query param), keeping index
 * endpoints declarative and individually testable.
 */
trait UsesPipeline
{
    /**
     * @return array<int, object>
     */
    abstract protected function filters(): array;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function pipeline(Builder $query): Builder
    {
        /** @var Builder<TModel> $result */
        $result = app(Pipeline::class)
            ->send($query)
            ->through($this->filters())
            ->thenReturn();

        return $result;
    }
}
