<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\WalletService;
use App\Support\Money;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;

function fakeUsageReport(array $days = []): void
{
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage*' => function ($request) use ($days) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $totalNanodollars = array_sum(array_map(
                fn (array $day): int => Money::fromUsdString((string) $day['cost_usd']),
                $days,
            ));

            return Http::response([
                'object' => 'usage',
                'start_date' => $query['start_date'] ?? null,
                'end_date' => $query['end_date'] ?? null,
                'data' => $days,
                'total' => [
                    'generations' => array_sum(array_column($days, 'generations')),
                    'cost_usd' => Money::toUsdString($totalNanodollars),
                ],
            ]);
        },
    ]);
}

function usageDay(string $date, int $generations, string $costUsd): array
{
    return [
        'date' => $date,
        'generations' => $generations,
        'input_text_tokens' => 10,
        'input_image_tokens' => 0,
        'output_tokens' => 100,
        'reasoning_tokens' => 50,
        'cost_usd' => $costUsd,
    ];
}

beforeEach(function (): void {
    seedPricingCacheAndKey();
});

it('redirects guests to login', function (): void {
    fakeUsageReport();

    $this->get(route('admin.report'))->assertRedirect();
});

it('forbids non-admin users', function (): void {
    fakeUsageReport();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.report'))
        ->assertForbidden();
});

it('renders the report page for admins', function (): void {
    fakeUsageReport();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Report')
            ->has('days')
            ->has('users')
            ->has('totals')
            ->has('range'));
});

it('groups succeeded generations by UTC completed_at date', function (): void {
    $admin = User::factory()->admin()->create();

    $dayA = now()->subDays(5)->startOfDay();
    $dayB = now()->subDays(3)->startOfDay();

    // Created on day A-1 but completed on day A — must group by completion day.
    Generation::factory()->succeeded()->create([
        'user_id' => $admin->id,
        'created_at' => $dayA->subDay()->endOfDay(),
        'completed_at' => $dayA->copy()->setTime(0, 5),
        'actual_user_cost' => 100_000_000,
        'actual_provider_cost' => 80_000_000,
    ]);

    Generation::factory()->succeeded()->create([
        'user_id' => $admin->id,
        'created_at' => $dayA,
        'completed_at' => $dayB->copy()->setTime(23, 59),
        'actual_user_cost' => 200_000_000,
        'actual_provider_cost' => 150_000_000,
    ]);

    fakeUsageReport([
        usageDay($dayA->toDateString(), 1, '0.080000000'),
        usageDay($dayB->toDateString(), 1, '0.150000000'),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.report', [
            'start_date' => $dayA->toDateString(),
            'end_date' => now()->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('days.0.date', $dayA->toDateString())
            ->where('days.0.generations', 1)
            ->where('days.0.user_spend_usd', '0.100000000')
            ->where('days.1.date', $dayB->toDateString())
            ->where('days.1.generations', 1)
            ->where('days.1.user_spend_usd', '0.200000000'));
});

it('excludes failed and pending generations from cost aggregates', function (): void {
    $admin = User::factory()->admin()->create();
    $day = now()->subDays(2)->startOfDay();

    Generation::factory()->succeeded()->create([
        'user_id' => $admin->id,
        'completed_at' => $day,
        'actual_user_cost' => 100_000_000,
        'actual_provider_cost' => 80_000_000,
    ]);

    Generation::factory()->failed()->create([
        'user_id' => $admin->id,
        'completed_at' => $day,
    ]);

    Generation::factory()->create([
        'user_id' => $admin->id,
        'completed_at' => null,
    ]);

    fakeUsageReport([usageDay($day->toDateString(), 1, '0.080000000')]);

    $this->actingAs($admin)
        ->get(route('admin.report', [
            'start_date' => $day->toDateString(),
            'end_date' => $day->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('totals.generations', 1)
            ->where('totals.user_spend_usd', '0.100000000')
            ->where('totals.provider_cost_usd', '0.080000000'));
});

it('computes per-user totals and exact profit', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create(['name' => 'Spender', 'email' => 'spender@example.com']);
    $day = now()->subDays(4)->startOfDay();

    Generation::factory()->succeeded()->create([
        'user_id' => $admin->id,
        'completed_at' => $day,
        'actual_user_cost' => 70_700_000,
        'actual_provider_cost' => 56_560_000,
    ]);

    Generation::factory()->count(2)->succeeded()->create([
        'user_id' => $other->id,
        'completed_at' => $day,
        'actual_user_cost' => 100_000_000,
        'actual_provider_cost' => 80_000_000,
    ]);

    fakeUsageReport([usageDay($day->toDateString(), 3, '0.216560000')]);

    $this->actingAs($admin)
        ->get(route('admin.report', [
            'start_date' => $day->toDateString(),
            'end_date' => $day->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('users.0.name', 'Spender')
            ->where('users.0.email', 'spender@example.com')
            ->where('users.0.generations', 2)
            ->where('users.0.user_spend_usd', '0.200000000')
            ->where('users.0.provider_cost_usd', '0.160000000')
            ->where('users.0.profit_usd', '0.040000000')
            ->where('totals.profit_usd', '0.054140000'));
});

it('reconciles local provider cost against Pixelforge usage', function (): void {
    $admin = User::factory()->admin()->create();
    $day = now()->subDays(6)->startOfDay();

    Generation::factory()->succeeded()->create([
        'user_id' => $admin->id,
        'completed_at' => $day,
        'actual_user_cost' => 100_000_000,
        'actual_provider_cost' => 80_000_000,
    ]);

    fakeUsageReport([usageDay($day->toDateString(), 1, '0.075000000')]);

    $this->actingAs($admin)
        ->get(route('admin.report', [
            'start_date' => $day->toDateString(),
            'end_date' => $day->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('days.0.reported_cost_usd', '0.075000000')
            ->where('days.0.delta_usd', '0.005000000')
            ->where('totals.delta_usd', '0.005000000'));

    Http::assertSent(fn ($request): bool => Str::startsWith($request->url(), 'https://pixelforge.promptedit.dev/v1/usage')
        && $request['start_date'] === $day->toDateString()
        && $request['end_date'] === $day->toDateString());
});

it('defaults to the last 30 days', function (): void {
    $admin = User::factory()->admin()->create();
    fakeUsageReport();

    $this->actingAs($admin)
        ->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('range.end_date', now()->toDateString())
            ->where('range.start_date', now()->subDays(29)->toDateString()));
});

it('rejects invalid ranges', function (array $query): void {
    $admin = User::factory()->admin()->create();
    fakeUsageReport();

    $this->actingAs($admin)
        ->get(route('admin.report', $query))
        ->assertSessionHasErrors();
})->with([
    'range over 92 days' => [fn (): array => [
        'start_date' => now()->subDays(120)->toDateString(),
        'end_date' => now()->subDays(10)->toDateString(),
    ]],
    'end before start' => [fn (): array => [
        'start_date' => now()->subDays(2)->toDateString(),
        'end_date' => now()->subDays(5)->toDateString(),
    ]],
    'malformed date' => [['start_date' => '11/01/2026']],
    'future end date' => [['end_date' => '2999-01-01']],
]);

it('surfaces provider-billed days with no local usage', function (): void {
    $admin = User::factory()->admin()->create();
    $day = now()->subDays(7)->startOfDay();

    // Pixelforge reports a billed day the local DB has no record of.
    fakeUsageReport([usageDay($day->toDateString(), 1, '0.050000000')]);

    $this->actingAs($admin)
        ->get(route('admin.report', [
            'start_date' => $day->toDateString(),
            'end_date' => $day->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('range.start_date', $day->toDateString())
            ->where('days.0.generations', 0)
            ->where('days.0.provider_cost_usd', '0.000000000')
            ->where('days.0.reported_cost_usd', '0.050000000')
            ->where('days.0.delta_usd', '-0.050000000'));
});

it('reports collected vs billed spend on wallet-capped shortfalls', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->create();
    $day = now()->subDays(4)->startOfDay();

    // Wallet only covers $0.06 — the $0.10 bill is partially uncollected.
    $wallet = $customer->wallet()->firstOrFail();
    $wallet->balance = 60_000_000;
    $wallet->save();

    $generation = Generation::factory()->succeeded()->create([
        'user_id' => $customer->id,
        'completed_at' => $day,
        'actual_user_cost' => 100_000_000,
        'actual_provider_cost' => 80_000_000,
    ]);

    app(WalletService::class)->holdForGeneration($customer, $generation, 60_000_000);
    app(WalletService::class)->settleGeneration($generation->refresh(), 100_000_000);

    fakeUsageReport([usageDay($day->toDateString(), 1, '0.080000000')]);

    $this->actingAs($admin)
        ->get(route('admin.report', [
            'start_date' => $day->toDateString(),
            'end_date' => $day->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('days.0.user_spend_usd', '0.100000000')
            ->where('days.0.collected_usd', '0.060000000')
            ->where('days.0.uncollected_usd', '0.040000000')
            ->where('totals.uncollected_usd', '0.040000000'));
});

it('retains billing history when a customer deletes their account', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->create();
    $day = now()->subDays(6)->startOfDay();

    Generation::factory()->succeeded()->create([
        'user_id' => $customer->id,
        'completed_at' => $day,
        'prompt' => 'sensitive prompt text',
        'actual_user_cost' => 100_000_000,
        'actual_provider_cost' => 80_000_000,
    ]);

    $customer->delete();

    // PII scrubbed, financial record retained for reconciliation.
    $orphan = Generation::query()->sole();
    expect($orphan->user_id)->toBeNull()
        ->and($orphan->prompt)->toBe('[deleted]')
        ->and($orphan->actual_provider_cost)->toBe(80_000_000);

    fakeUsageReport([usageDay($day->toDateString(), 1, '0.080000000')]);

    $this->actingAs($admin)
        ->get(route('admin.report', [
            'start_date' => $day->toDateString(),
            'end_date' => $day->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('totals.user_spend_usd', '0.100000000')
            ->where('totals.provider_cost_usd', '0.080000000')
            ->where('totals.delta_usd', '0.000000000')
            ->where('users.0.name', 'Deleted users')
            ->where('users.0.generations', 1));
});

it('seeds an admin only when the email is unregistered', function (): void {
    config(['app.seed_admin.email' => 'admin@example.com', 'app.seed_admin.password' => 'secret']);

    $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])
        ->assertSuccessful();

    expect(User::where('email', 'admin@example.com')->sole()->is_admin)->toBeTrue();
});

it('seeds an admin with a generated password when none is configured', function (): void {
    config(['app.seed_admin.email' => 'admin@example.com', 'app.seed_admin.password' => null]);

    $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])
        ->expectsOutputToContain('generated password')
        ->assertSuccessful();

    $user = User::where('email', 'admin@example.com')->sole();

    expect($user->is_admin)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->toStartWith('$2y$');
});

it('refuses to seed over an existing account', function (): void {
    $existing = User::factory()->create(['email' => 'admin@example.com']);
    config(['app.seed_admin.email' => 'admin@example.com', 'app.seed_admin.password' => 'secret']);

    expect(fn () => $this->artisan('db:seed', ['--class' => AdminUserSeeder::class]))
        ->toThrow(RuntimeException::class, 'already registered');

    expect($existing->refresh()->is_admin)->toBeFalse();
});

it('promotes a user to admin via user:make-admin', function (): void {
    $user = User::factory()->create();

    $this->artisan('user:make-admin', ['email' => $user->email])
        ->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();
});

it('fails user:make-admin for an unknown email', function (): void {
    $this->artisan('user:make-admin', ['email' => 'nobody@example.com'])
        ->assertFailed();
});
