<?php

namespace App\Http\Controllers\Generations;

use App\Actions\Generations\CreateGenerationAction;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Generations/Create', [
            'sizes' => array_map(
                fn (ImageSize $size): array => ['value' => $size->value, 'label' => $size->label()],
                ImageSize::cases(),
            ),
            'qualities' => array_map(
                fn (ImageQuality $quality): array => ['value' => $quality->value, 'label' => $quality->label()],
                ImageQuality::cases(),
            ),
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGenerationAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        /** @var list<string> $referenceImages */
        $referenceImages = $validated['reference_images'] ?? [];

        $generation = $action->execute(
            user: $user,
            rawPrompt: (string) $validated['prompt'],
            size: ImageSize::from((string) $validated['size']),
            quality: ImageQuality::from((string) $validated['quality']),
            referenceImages: $referenceImages,
        );

        return to_route('generations.create')->with('status', "Generation #{$generation->id} queued.");
    }
}
