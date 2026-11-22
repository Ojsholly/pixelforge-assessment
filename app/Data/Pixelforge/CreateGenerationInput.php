<?php

namespace App\Data\Pixelforge;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;

final readonly class CreateGenerationInput
{
    /**
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $prompt,
        public ImageSize $size,
        public ImageQuality $quality,
        public string $idempotencyKey,
        public array $referenceImages = [],
        public array $metadata = [],
    ) {}
}
