<?php

namespace App\States\Generation;

use App\Enums\GenerationStatus;

final class Succeeded extends GenerationState
{
    public static string $name = 'succeeded';

    public function status(): GenerationStatus
    {
        return GenerationStatus::Succeeded;
    }
}
