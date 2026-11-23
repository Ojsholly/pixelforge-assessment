<?php

namespace App\Http\Controllers\Generations;

use App\Actions\Generations\ApplyGenerationStateAction;
use App\Actions\Generations\CreateGenerationAction;
use App\Concerns\UsesPipeline;
use App\Contracts\ImageGenerationProvider;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Filters\Generations\StatusFilter;
use App\Filters\Sort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Models\Generation;
use App\Models\User;
use App\Support\GenerationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    use UsesPipeline;

    public function index(Request $request, GenerationPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        $generations = $this->pipeline(Generation::query()->where('user_id', $user->id))
            ->paginate(12)
            ->withQueryString()
            ->through($presenter->summary(...));

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
            'counts' => $presenter->statusCounts($user),
            'filters' => [
                'status' => $this->statusFilter()?->value,
                'sort' => $this->sortFilter()->current(),
            ],
        ]);
    }

    public function show(Generation $generation, GenerationPresenter $presenter): Response
    {
        Gate::authorize('view', $generation);

        return Inertia::render('Generations/Show', [
            'generation' => $presenter->detail($generation),
        ]);
    }

    public function refresh(
        Generation $generation,
        ImageGenerationProvider $provider,
        ApplyGenerationStateAction $applyState,
    ): RedirectResponse {
        Gate::authorize('refresh', $generation);

        if ($generation->provider_id === null) {
            return back()->withErrors([
                'provider' => 'This generation has not been submitted to the provider yet.',
            ]);
        }

        $applyState->execute($generation, $provider->getGeneration($generation->provider_id));

        return back();
    }

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

    /**
     * @return array<int, object>
     */
    protected function filters(): array
    {
        return [
            new StatusFilter($this->statusFilter()),
            $this->sortFilter(),
        ];
    }

    private function statusFilter(): ?GenerationStatus
    {
        $status = request()->query('status');

        return is_string($status) ? GenerationStatus::tryFrom($status) : null;
    }

    private function sortFilter(): Sort
    {
        $sort = request()->query('sort');

        return new Sort(
            sort: is_string($sort) ? $sort : null,
            allowedColumns: ['created_at'],
            defaultColumn: 'created_at',
        );
    }
}
