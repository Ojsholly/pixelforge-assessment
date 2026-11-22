<?php

namespace App\Http\Controllers\Generations;

use App\Contracts\ImageGenerationProvider;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\ProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Generations\EstimateGenerationRequest;
use App\Services\PricingCalculator;
use App\Support\PromptSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EstimateGenerationController extends Controller
{
    public function __invoke(
        EstimateGenerationRequest $request,
        ImageGenerationProvider $provider,
        PricingCalculator $calculator,
    ): JsonResponse {
        $validated = $request->validated();

        $rawPrompt = (string) ($validated['prompt'] ?? '');
        $prompt = '';

        if (trim($rawPrompt) !== '') {
            try {
                $prompt = PromptSanitizer::sanitize($rawPrompt);
            } catch (InvalidArgumentException $invalidArgumentException) {
                throw ValidationException::withMessages([
                    'prompt' => $invalidArgumentException->getMessage(),
                ]);
            }
        }

        /** @var list<string> $referenceImages */
        $referenceImages = $validated['reference_images'] ?? [];

        try {
            $pricing = $provider->getPricing();
        } catch (ProviderException) {
            return response()->json([
                'message' => 'Unable to fetch pricing estimate right now. Please try again.',
            ], 503);
        }

        $estimate = $calculator->estimate(
            prompt: $prompt,
            size: ImageSize::from((string) $validated['size']),
            quality: ImageQuality::from((string) $validated['quality']),
            referenceImages: $referenceImages,
            pricing: $pricing,
        );

        return response()->json($estimate->toArray());
    }
}
