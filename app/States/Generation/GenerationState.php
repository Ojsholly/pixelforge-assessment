<?php

namespace App\States\Generation;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * @extends State<Generation>
 */
abstract class GenerationState extends State
{
    abstract public function status(): GenerationStatus;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Queued::class)
            ->allowTransition(Queued::class, Processing::class)
            ->allowTransition(Queued::class, Succeeded::class)
            ->allowTransition(Queued::class, Failed::class)
            ->allowTransition(Processing::class, Succeeded::class)
            ->allowTransition(Processing::class, Failed::class);
    }

    public static function fromStatus(GenerationStatus $status): string
    {
        return match ($status) {
            GenerationStatus::Queued => Queued::class,
            GenerationStatus::Processing => Processing::class,
            GenerationStatus::Succeeded => Succeeded::class,
            GenerationStatus::Failed => Failed::class,
        };
    }
}
