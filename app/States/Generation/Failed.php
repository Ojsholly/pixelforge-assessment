<?php

namespace App\States\Generation;

use App\Enums\GenerationStatus;

final class Failed extends GenerationState
{
    public static string $name = 'failed';

    public function status(): GenerationStatus
    {
        return GenerationStatus::Failed;
    }
}
