<?php

namespace App\Http\Controllers\Generations;

use App\Contracts\ImageGenerationProvider;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\Generations\EstimateGenerationRequest;
use App\Models\User;
use App\Services\PricingCalculator;
use App\Services\WalletService;
use App\Support\PromptSanitizer;
use Illuminate\Http\JsonResponse;

class EstimateGenerationController extends Controller
{
    public function __invoke(
        EstimateGenerationRequest $request,
        ImageGenerationProvider $provider,
        PricingCalculator $calculator,
        WalletService $wallets,
    ): JsonResponse {
        $validated = $request->validated();

        $rawPrompt = (string) ($validated['prompt'] ?? '');
        $prompt = trim($rawPrompt) !== '' ? PromptSanitizer::sanitize($rawPrompt) : '';

        /** @var list<string> $referenceImages */
        $referenceImages = $validated['reference_images'] ?? [];

        $estimate = $calculator->estimate(
            prompt: $prompt,
            size: ImageSize::from((string) $validated['size']),
            quality: ImageQuality::from((string) $validated['quality']),
            referenceImages: $referenceImages,
            pricing: $provider->getPricing(),
        );

        // Include the current wallet balance so the Studio's insufficient-funds
        // hint reflects background refunds/holds, not just the last page load.
        /** @var User $user */
        $user = $request->user();
        $wallet = $wallets->ensureWallet($user);

        return response()->json([
            ...$estimate->toArray(),
            'wallet_balance_nanodollars' => $wallet->balance,
            'wallet_balance_display' => $wallet->formatted_balance,
        ]);
    }
}
