<?php

use App\Enums\TransactionType;
use App\Models\Generation;
use App\Models\User;
use App\States\Generation\Succeeded;
use Illuminate\Support\Facades\Http;

it('requires authentication for history, detail, and refresh routes', function (): void {
    $generation = Generation::factory()->create();

    $this->get(route('generations.index'))->assertRedirect(route('login'));
    $this->get(route('generations.show', $generation))->assertRedirect(route('login'));
    $this->post(route('generations.refresh', $generation))->assertRedirect(route('login'));
});

it('lists only the authenticated user\'s generations', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Generation::factory()->count(2)->succeeded()->for($user)->create();
    Generation::factory()->count(1)->processing()->for($user)->create();
    Generation::factory()->count(1)->succeeded()->for($other)->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Generations/Index')
            ->has('generations.data', 3)
            ->where('counts.total', 3)
            ->where('counts.succeeded', 2)
            ->where('counts.processing', 1)
            ->where('generations.data.0.is_pending', true)
        );
});

it('filters generations by status and echoes the active filter', function (): void {
    $user = User::factory()->create();

    Generation::factory()->count(2)->succeeded()->for($user)->create();
    Generation::factory()->count(1)->failed()->for($user)->create();
    Generation::factory()->count(1)->processing()->for($user)->create();

    $this->actingAs($user)
        ->get(route('generations.index', ['status' => 'succeeded']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('generations.data', 2)
            ->where('filters.status', 'succeeded')
            ->where('generations.total', 2)
        );

    $this->actingAs($user)
        ->get(route('generations.index', ['status' => 'failed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('generations.data', 1)
            ->where('filters.status', 'failed')
        );
});

it('ignores an invalid status filter', function (): void {
    $user = User::factory()->create();
    Generation::factory()->count(2)->for($user)->create();

    $this->actingAs($user)
        ->get(route('generations.index', ['status' => 'bogus']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('generations.data', 2)
            ->where('filters.status', null)
        );
});

it('paginates generation history', function (): void {
    $user = User::factory()->create();
    Generation::factory()->count(13)->for($user)->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('generations.data', 12)
            ->where('generations.total', 13)
            ->where('generations.last_page', 2)
        );

    $this->actingAs($user)
        ->get(route('generations.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('generations.data', 1));
});

it('shows a generation to its owner and forbids other users', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $generation = Generation::factory()->succeeded()->for($user)->create();

    $this->actingAs($user)
        ->get(route('generations.show', $generation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Generations/Show')
            ->where('generation.id', $generation->id)
            ->where('generation.status', 'succeeded')
            ->has('generation.estimated_tokens')
            ->has('generation.actual_usage')
            ->has('generation.rates_snapshot')
        );

    $this->actingAs($other)
        ->get(route('generations.show', $generation))
        ->assertForbidden();
});

it('exposes presenter flags for free, pending, and untrusted image urls', function (): void {
    $user = User::factory()->create();

    $failed = Generation::factory()->failed()->for($user)->create();
    $pending = Generation::factory()->processing()->for($user)->create();
    $httpImage = Generation::factory()->succeeded()->for($user)->create([
        'image_url' => 'http://pixelforge.promptedit.dev/images/x.png',
    ]);

    $this->actingAs($user)
        ->get(route('generations.show', $failed))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('generation.is_free', true)
            ->where('generation.is_pending', false)
            ->where('generation.actual_user_cost_display', '$0.00')
        );

    $this->actingAs($user)
        ->get(route('generations.show', $pending))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('generation.is_pending', true));

    $this->actingAs($user)
        ->get(route('generations.show', $httpImage))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('generation.image_url', null));
});

it('reports a provider error when refreshing an unsubmitted generation', function (): void {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create();

    $this->actingAs($user)
        ->from(route('generations.show', $generation))
        ->post(route('generations.refresh', $generation))
        ->assertRedirect(route('generations.show', $generation))
        ->assertSessionHasErrors('provider');
});

it('forbids refreshing another user\'s generation', function (): void {
    $other = User::factory()->create();
    $generation = Generation::factory()->processing()->create();

    $this->actingAs($other)
        ->post(route('generations.refresh', $generation))
        ->assertForbidden();
});

it('manually refreshes a pending generation from the provider idempotently', function (): void {
    config(['services.pixelforge.api_key' => 'test-key']);

    $user = User::factory()->create();
    $generation = Generation::factory()->processing()->for($user)->create([
        'provider_id' => 'gen_ref_1',
        'reserved_amount' => 86_337_500,
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations/gen_ref_1' => Http::response([
            'id' => 'gen_ref_1',
            'status' => 'succeeded',
            'rates' => $generation->rates_snapshot,
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_ref_1.png',
            'image_url_expires_at' => '2026-11-22T13:00:00Z',
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 1000,
                'total_tokens' => 2112,
            ],
            'completed_at' => '2026-11-22T12:00:15Z',
        ], 200),
    ]);

    $this->actingAs($user)
        ->from(route('generations.show', $generation))
        ->post(route('generations.refresh', $generation))
        ->assertRedirect(route('generations.show', $generation));

    $generation->refresh();

    expect($generation->status)->toBeInstanceOf(Succeeded::class)
        ->and($generation->actual_user_cost)->toBe(70_700_000)
        ->and($generation->image_url)->toBe('https://pixelforge.promptedit.dev/images/gen_ref_1.png')
        ->and($generation->transactions()->where('type', TransactionType::Release)->count())->toBe(1);

    $transactionsBefore = $generation->transactions()->count();

    $this->actingAs($user)
        ->from(route('generations.show', $generation))
        ->post(route('generations.refresh', $generation))
        ->assertRedirect(route('generations.show', $generation));

    expect($generation->refresh()->status)->toBeInstanceOf(Succeeded::class)
        ->and($generation->transactions()->count())->toBe($transactionsBefore);
});

it('renders the dashboard with wallet balance, counts, and recent generations', function (): void {
    $user = User::factory()->create();

    Generation::factory()->count(2)->succeeded()->for($user)->create();
    Generation::factory()->count(1)->processing()->for($user)->create();
    Generation::factory()->count(1)->succeeded()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('walletBalance')
            ->where('counts.total', 3)
            ->where('counts.succeeded', 2)
            ->where('counts.processing', 1)
            ->has('recentGenerations', 3)
        );
});
