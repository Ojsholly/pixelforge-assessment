<?php

namespace App\States\Generation;

use App\Enums\GenerationStatus;

final class Processing extends GenerationState
{
    public static string $name = 'processing';

    public function status(): GenerationStatus
    {
        return GenerationStatus::Processing;
    }
}
