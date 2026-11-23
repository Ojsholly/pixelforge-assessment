<?php

use App\Contracts\ImageGenerationProvider;
use App\Enums\TransactionType;
use App\Models\Generation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\WalletService;
use App\States\Generation\Failed;
use App\States\Generation\Processing;
use App\States\Generation\Queued;
use App\States\Generation\Succeeded;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

function signWebhookPayload(string $rawBody, string $secret = 'whsec_test_secret', ?int $timestamp = null): string
{
    $ts = $timestamp ?? time();
    $sig = hash_hmac('sha256', "{$ts}.{$rawBody}", $secret);

    return "t={$ts},v1={$sig}";
}

it('rejects missing, invalid, tampered, or expired webhook signatures with 400', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $payload = json_encode([
        'id' => 'evt_bad_1',
        'object' => 'event',
        'type' => 'ping',
        'data' => ['object' => []],
    ], JSON_THROW_ON_ERROR);

    // Missing signature header
    $this->postJson(route('webhooks.pixelforge'), json_decode($payload, true))
        ->assertStatus(400);

    // Invalid signature
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => 't='.time().',v1='.Str::repeat('a', 64),
    ], $payload)->assertStatus(400);

    // Tampered body
    $validSigForOriginal = signWebhookPayload($payload);
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $validSigForOriginal,
    ], Str::replace('evt_bad_1', 'evt_tampered', $payload))->assertStatus(400);

    // Expired timestamp (> 300s old)
    $expiredSig = signWebhookPayload($payload, 'whsec_test_secret', time() - 301);
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $expiredSig,
    ], $payload)->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
});

it('processes generation.succeeded idempotently and never double-bills on duplicate delivery', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $holdAmount = 100_000_000;
    $wallet->balance = (5 * Money::NANODOLLARS_PER_USD) - $holdAmount;
    $wallet->save();

    $generation = Generation::factory()->submitted()->for($user)->create([
        'provider_id' => 'gen_wh_1',
        'reserved_amount' => $holdAmount,
        'estimated_max_user_cost' => $holdAmount,
    ]);

    $rawBody = json_encode([
        'id' => 'evt_dup_1',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_wh_1',
                'status' => 'succeeded',
                'rates' => $generation->rates_snapshot,
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_wh_1.png',
                'image_url_expires_at' => '2026-11-23T12:00:15Z',
                'usage' => [
                    'input_text_tokens' => 10,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 500,
                    'total_tokens' => 1610,
                ],
                'completed_at' => '2026-11-22T12:00:15Z',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
    ];

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $headers, $rawBody)
        ->assertNoContent();

    // Exact same event delivered a second time
    $headers['HTTP_PIXELFORGE_DELIVERY_ATTEMPT'] = '2';
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], $headers, $rawBody)
        ->assertNoContent();

    $generation->refresh();

    expect(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::firstOrFail()->processed_at)->not->toBeNull()
        ->and($generation->status)->toBeInstanceOf(Succeeded::class)
        ->and($generation->actual_user_cost)->toBe(62_875_000)
        ->and($wallet->refresh()->balance)->toBe((5 * Money::NANODOLLARS_PER_USD) - 62_875_000)
        ->and($generation->transactions()->where('type', TransactionType::Release)->count())->toBe(1);
});

it('safely ignores out-of-order generation.processing after succeeded or failed', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $succeededGen = Generation::factory()->succeeded()->create([
        'provider_id' => 'gen_ooo_1',
    ]);

    $failedGen = Generation::factory()->failed()->create([
        'provider_id' => 'gen_ooo_2',
    ]);

    foreach ([['evt_ooo_1', 'gen_ooo_1'], ['evt_ooo_2', 'gen_ooo_2']] as [$evtId, $genId]) {
        $rawBody = json_encode([
            'id' => $evtId,
            'object' => 'event',
            'type' => 'generation.processing',
            'data' => [
                'object' => [
                    'id' => $genId,
                    'status' => 'processing',
                    'rates' => $succeededGen->rates_snapshot,
                    'started_at' => '2026-11-22T12:00:05Z',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
        ], $rawBody)->assertNoContent();
    }

    expect($succeededGen->refresh()->status)->toBeInstanceOf(Succeeded::class)
        ->and($failedGen->refresh()->status)->toBeInstanceOf(Failed::class);
});

it('matches an early webhook via metadata.local_generation_id before provider_id is stored', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $generation = Generation::factory()->create([
        'provider_id' => null,
    ]);

    $rawBody = json_encode([
        'id' => 'evt_early_1',
        'object' => 'event',
        'type' => 'generation.processing',
        'data' => [
            'object' => [
                'id' => 'gen_early_999',
                'status' => 'processing',
                'rates' => $generation->rates_snapshot,
                'metadata' => ['local_generation_id' => (string) $generation->id],
                'started_at' => '2026-11-22T12:00:05Z',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
    ], $rawBody)->assertNoContent();

    $generation->refresh();

    expect($generation->provider_id)->toBe('gen_early_999')
        ->and($generation->status)->toBeInstanceOf(Processing::class);
});

it('re-processes an unprocessed webhook event when processed_at is null', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $generation = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_retry_1',
    ]);

    $payload = [
        'id' => 'evt_crash_1',
        'object' => 'event',
        'type' => 'generation.failed',
        'data' => [
            'object' => [
                'id' => 'gen_retry_1',
                'status' => 'failed',
                'rates' => $generation->rates_snapshot,
                'error' => ['code' => 'generation_failed', 'message' => 'Failed'],
                'completed_at' => '2026-11-22T12:00:10Z',
            ],
        ],
    ];

    WebhookEvent::factory()->create([
        'provider_event_id' => 'evt_crash_1',
        'event_type' => 'generation.failed',
        'provider_generation_id' => 'gen_retry_1',
        'payload' => $payload,
        'processed_at' => null,
    ]);

    $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
    ], $rawBody)->assertNoContent();

    expect($generation->refresh()->status)->toBeInstanceOf(Failed::class)
        ->and(WebhookEvent::firstWhere('provider_event_id', 'evt_crash_1')?->processed_at)->not->toBeNull();
});

it('handles ping, unknown future events, and orphan events safely with 204', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $events = [
        ['id' => 'evt_ping_1', 'object' => 'event', 'type' => 'ping', 'data' => ['object' => []]],
        ['id' => 'evt_future_1', 'object' => 'event', 'type' => 'generation.upscaled', 'data' => ['object' => ['id' => 'gen_x']]],
        [
            'id' => 'evt_orphan_1',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'data' => [
                'object' => [
                    'id' => 'gen_does_not_exist',
                    'status' => 'succeeded',
                    'rates' => validPricingPayload()['rates'],
                ],
            ],
        ],
    ];

    foreach ($events as $event) {
        $rawBody = json_encode($event, JSON_THROW_ON_ERROR);

        $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
        ], $rawBody)->assertNoContent();
    }

    expect(WebhookEvent::count())->toBe(3)
        ->and(WebhookEvent::whereNotNull('processed_at')->count())->toBe(3);
});

it('reconciles stale queued and processing generations via artisan command', function (): void {
    config(['services.pixelforge.api_key' => 'test-key']);

    $stale = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_stale_1',
        'updated_at' => now()->subMinutes(5),
    ]);

    $fresh = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_fresh_1',
        'updated_at' => now(),
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations/gen_stale_1' => Http::response([
            'id' => 'gen_stale_1',
            'status' => 'succeeded',
            'rates' => $stale->rates_snapshot,
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_stale_1.png',
            'usage' => [
                'input_text_tokens' => 10,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 500,
                'total_tokens' => 1610,
            ],
            'completed_at' => '2026-11-22T12:00:15Z',
        ], 200),
    ]);

    $this->artisan('generations:reconcile-stale')
        ->expectsOutput('Reconciled 1 stale generation(s).')
        ->assertSuccessful();

    expect($stale->refresh()->status)->toBeInstanceOf(Succeeded::class)
        ->and($fresh->refresh()->status)->not->toBeInstanceOf(Succeeded::class);

    Http::assertSentCount(1);
});

it('acknowledges malformed generation webhook objects without 500 and fails stale generations the provider no longer has', function (): void {
    config([
        'services.pixelforge.webhook_secret' => 'whsec_test_secret',
        'services.pixelforge.api_key' => 'test-key',
    ]);

    $generation = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_malformed_1',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    $rawBody = json_encode([
        'id' => 'evt_malformed_obj',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_malformed_1',
                'status' => 'succeeded',
                // missing rates
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
    ], $rawBody)->assertNoContent();

    expect(WebhookEvent::firstWhere('provider_event_id', 'evt_malformed_obj')?->processed_at)->not->toBeNull();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations/gen_malformed_1' => Http::response([], 404),
    ]);

    $this->artisan('generations:reconcile-stale --max-age=60')
        ->expectsOutput('Reconciled 1 stale generation(s).')
        ->assertSuccessful();

    expect($generation->refresh()->status)->toBeInstanceOf(Failed::class)
        ->and($generation->error_code)->toBe('reconciliation_not_found');
});

it('times out generations the provider still reports as pending past max age', function (): void {
    config(['services.pixelforge.api_key' => 'test-key']);

    $expired = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_pending_old',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subMinutes(5),
    ]);

    $withinLimit = Generation::factory()->processing()->create([
        'provider_id' => 'gen_pending_new',
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(5),
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations/gen_pending_old' => Http::response([
            'id' => 'gen_pending_old',
            'status' => 'processing',
            'rates' => $expired->rates_snapshot,
            'started_at' => now()->subHour()->toIso8601String(),
        ], 200),
        'https://pixelforge.promptedit.dev/v1/generations/gen_pending_new' => Http::response([
            'id' => 'gen_pending_new',
            'status' => 'processing',
            'rates' => $withinLimit->rates_snapshot,
            'started_at' => now()->subMinutes(10)->toIso8601String(),
        ], 200),
    ]);

    $this->artisan('generations:reconcile-stale --max-age=60')
        ->expectsOutput('Reconciled 1 stale generation(s).')
        ->assertSuccessful();

    expect($expired->refresh()->status)->toBeInstanceOf(Failed::class)
        ->and($expired->error_code)->toBe('reconciliation_timeout')
        ->and($withinLimit->refresh()->status)->toBeInstanceOf(Processing::class);
});

it('treats malformed poll responses as transient and fails them only past the hard max age', function (): void {
    config(['services.pixelforge.api_key' => 'test-key']);

    $recent = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_malformed_recent',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subMinutes(5),
    ]);

    $ancient = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_malformed_ancient',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subMinutes(5),
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations/gen_malformed_recent' => Http::response([
            'id' => 'gen_malformed_recent',
            'status' => ['processing'],
            'rates' => $recent->rates_snapshot,
        ], 200),
        'https://pixelforge.promptedit.dev/v1/generations/gen_malformed_ancient' => Http::response([
            'id' => 'gen_malformed_ancient',
            'status' => ['processing'],
            'rates' => $ancient->rates_snapshot,
        ], 200),
    ]);

    $this->artisan('generations:reconcile-stale')
        ->expectsOutput('Reconciled 1 stale generation(s).')
        ->assertSuccessful();

    expect($recent->refresh()->status)->toBeInstanceOf(Queued::class)
        ->and($ancient->refresh()->status)->toBeInstanceOf(Failed::class)
        ->and($ancient->error_code)->toBe('reconciliation_unreachable');
});

it('skips transient provider errors and only fails generations past the hard max age', function (): void {
    config(['services.pixelforge.api_key' => 'test-key']);

    $recent = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_outage_recent',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subMinutes(5),
    ]);

    $ancient = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_outage_ancient',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subMinutes(5),
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/generations/*' => Http::response('Service unavailable', 503),
    ]);

    $this->artisan('generations:reconcile-stale')
        ->expectsOutput('Reconciled 1 stale generation(s).')
        ->assertSuccessful();

    expect($recent->refresh()->status)->toBeInstanceOf(Queued::class)
        ->and($ancient->refresh()->status)->toBeInstanceOf(Failed::class)
        ->and($ancient->error_code)->toBe('reconciliation_unreachable');
});

it('releases expired holds after unexpected polling failures', function (): void {
    $recent = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_unexpected_recent',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subMinutes(5),
    ]);

    $ancient = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_unexpected_ancient',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subMinutes(5),
    ]);

    // A non-ProviderException escaping getGeneration must still honor the hard cap.
    $this->mock(ImageGenerationProvider::class)
        ->shouldReceive('getGeneration')
        ->andThrow(new RuntimeException('unexpected poll failure'));

    $this->artisan('generations:reconcile-stale')
        ->expectsOutput('Reconciled 1 stale generation(s).')
        ->assertSuccessful();

    expect($recent->refresh()->status)->toBeInstanceOf(Queued::class)
        ->and($ancient->refresh()->status)->toBeInstanceOf(Failed::class)
        ->and($ancient->error_code)->toBe('reconciliation_unreachable');
});

it('settles succeeded webhooks despite unparseable optional timestamps', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $generation = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_bad_ts',
    ]);

    $rawBody = json_encode([
        'id' => 'evt_bad_ts',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_bad_ts',
                'status' => 'succeeded',
                'rates' => $generation->rates_snapshot,
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_bad_ts.png',
                'usage' => [
                    'input_text_tokens' => 10,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 500,
                    'total_tokens' => 1610,
                ],
                'completed_at' => 'not-a-timestamp',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
    ], $rawBody)->assertNoContent();

    expect(WebhookEvent::firstWhere('provider_event_id', 'evt_bad_ts')?->processed_at)->not->toBeNull()
        ->and($generation->refresh()->status)->toBeInstanceOf(Succeeded::class)
        ->and($generation->completed_at)->not->toBeNull();
});

it('rolls back settlement and processed_at together when the state action throws', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $user = User::factory()->create();

    $generation = Generation::factory()->processing()->for($user)->create([
        'provider_id' => 'gen_atomic_1',
        'reserved_amount' => 50_000_000,
        'estimated_max_user_cost' => 50_000_000,
    ]);

    // Force a mid-transaction failure: the settlement wallet lookup now fails.
    $user->wallet()->firstOrFail()->delete();

    $rawBody = json_encode([
        'id' => 'evt_atomic_1',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_atomic_1',
                'status' => 'succeeded',
                'rates' => $generation->rates_snapshot,
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_atomic_1.png',
                'usage' => [
                    'input_text_tokens' => 10,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 500,
                    'total_tokens' => 1610,
                ],
                'completed_at' => '2026-11-22T12:00:15Z',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
    ], $rawBody)->assertNotFound();

    $event = WebhookEvent::firstWhere('provider_event_id', 'evt_atomic_1');

    expect($event)->not->toBeNull()
        ->and($event->processed_at)->toBeNull()
        ->and($generation->refresh()->status)->toBeInstanceOf(Processing::class)
        ->and(Transaction::count())->toBe(0);
});

it('acknowledges succeeded payloads missing usage without settling', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $user = User::factory()->create();
    $generation = Generation::factory()->processing()->for($user)->create([
        'provider_id' => 'gen_incomplete_1',
        'reserved_amount' => 50_000_000,
    ]);

    $rawBody = json_encode([
        'id' => 'evt_incomplete_1',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_incomplete_1',
                'status' => 'succeeded',
                'rates' => $generation->rates_snapshot,
                'completed_at' => '2026-11-22T12:00:15Z',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
    ], $rawBody)->assertNoContent();

    expect($generation->refresh()->status)->toBeInstanceOf(Processing::class)
        ->and($generation->reserved_amount)->toBe(50_000_000)
        ->and($generation->transactions()->count())->toBe(0)
        ->and(WebhookEvent::firstWhere('provider_event_id', 'evt_incomplete_1')?->processed_at)->not->toBeNull();
});

it('deletes webhook events belonging to a user when their account is deleted', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Generation::factory()->submitted()->for($user)->create(['provider_id' => 'gen_del_1']);
    Generation::factory()->submitted()->for($other)->create(['provider_id' => 'gen_del_2']);

    WebhookEvent::factory()->create(['provider_generation_id' => 'gen_del_1', 'processed_at' => now()]);
    WebhookEvent::factory()->create(['provider_generation_id' => 'gen_del_2', 'processed_at' => now()]);
    WebhookEvent::factory()->create(['provider_generation_id' => null, 'processed_at' => now()]);

    $user->delete();

    expect(WebhookEvent::where('provider_generation_id', 'gen_del_1')->count())->toBe(0)
        ->and(WebhookEvent::where('provider_generation_id', 'gen_del_2')->count())->toBe(1)
        ->and(WebhookEvent::whereNull('provider_generation_id')->count())->toBe(1)
        ->and(Generation::where('user_id', $user->id)->count())->toBe(0);
});

it('prunes processed webhook events older than thirty days', function (): void {
    $expired = WebhookEvent::factory()->create([
        'processed_at' => now()->subDays(31),
        'created_at' => now()->subDays(31),
    ]);
    $recent = WebhookEvent::factory()->create([
        'processed_at' => now(),
        'created_at' => now()->subDay(),
    ]);
    $unprocessed = WebhookEvent::factory()->create([
        'processed_at' => null,
        'created_at' => now()->subDays(31),
    ]);

    $this->artisan('model:prune', ['--model' => WebhookEvent::class])->assertSuccessful();

    expect(WebhookEvent::find($expired->id))->toBeNull()
        ->and(WebhookEvent::find($recent->id))->not->toBeNull()
        ->and(WebhookEvent::find($unprocessed->id))->not->toBeNull();
});

it('ignores a stale event that claims a generation already bound to a provider id', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $generation = Generation::factory()->submitted()->create([
        'provider_id' => 'gen_real_1',
    ]);

    $rawBody = json_encode([
        'id' => 'evt_stale_claim',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_stale_404',
                'status' => 'succeeded',
                'rates' => $generation->rates_snapshot,
                'image_url' => 'https://pixelforge.promptedit.dev/images/stale.png',
                'usage' => [
                    'input_text_tokens' => 10,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 500,
                    'total_tokens' => 1610,
                ],
                'metadata' => ['local_generation_id' => (string) $generation->id],
                'completed_at' => '2026-11-22T12:00:15Z',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
    ], $rawBody)->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBeInstanceOf(Queued::class)
        ->and($generation->provider_id)->toBe('gen_real_1')
        ->and($generation->image_url)->toBeNull();
});

it('keeps image fields null when a retained generation succeeds after account deletion', function (): void {
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret']);

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    $generation = Generation::factory()->submitted()->for($user)->create([
        'provider_id' => 'gen_orphan_1',
    ]);

    app(WalletService::class)->holdForGeneration($user, $generation, 100_000_000);

    $user->delete();

    expect($generation->refresh()->user_id)->toBeNull()
        ->and($wallet->refresh()->user_id)->toBeNull();

    $rawBody = json_encode([
        'id' => 'evt_orphan_1',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'data' => [
            'object' => [
                'id' => 'gen_orphan_1',
                'status' => 'succeeded',
                'rates' => $generation->rates_snapshot,
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_orphan_1.png',
                'image_url_expires_at' => '2026-11-23T12:00:15Z',
                'usage' => [
                    'input_text_tokens' => 10,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 500,
                    'total_tokens' => 1610,
                ],
                'completed_at' => '2026-11-22T12:00:15Z',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => signWebhookPayload($rawBody),
    ], $rawBody)->assertNoContent();

    $generation->refresh();

    // Billing data lands and the hold settles on the anonymized wallet, but
    // no media reattaches to a generation whose owner was deleted.
    expect($generation->status)->toBeInstanceOf(Succeeded::class)
        ->and($generation->actual_user_cost)->toBe(62_875_000)
        ->and($generation->image_url)->toBeNull()
        ->and($generation->image_url_expires_at)->toBeNull()
        ->and(
            Transaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', TransactionType::Release->value)
                ->exists()
        )->toBeTrue();
});
