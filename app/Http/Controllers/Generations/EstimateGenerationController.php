<?php

namespace App\Http\Controllers\Generations;

use App\Contracts\ImageGenerationProvider;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\Generations\EstimateGenerationRequest;
use App\Services\PricingCalculator;
use Illuminate\Http\JsonResponse;

class EstimateGenerationController extends Controller
{
    public function __invoke(
        EstimateGenerationRequest $request,
        ImageGenerationProvider $provider,
        PricingCalculator $calculator,
    ): JsonResponse {
        $validated = $request->validated();

        /** @var list<string> $referenceImages */
        $referenceImages = $validated['reference_images'] ?? [];

        $estimate = $calculator->estimate(
            prompt: (string) ($validated['prompt'] ?? ''),
            size: ImageSize::from((string) $validated['size']),
            quality: ImageQuality::from((string) $validated['quality']),
            referenceImages: $referenceImages,
            pricing: $provider->getPricing(),
        );

        return response()->json($estimate->toArray());
    }
}
