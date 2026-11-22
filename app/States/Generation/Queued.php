<?php

namespace App\States\Generation;

use App\Enums\GenerationStatus;

final class Queued extends GenerationState
{
    public static string $name = 'queued';

    public function status(): GenerationStatus
    {
        return GenerationStatus::Queued;
    }
}
