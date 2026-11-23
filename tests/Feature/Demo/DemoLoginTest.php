<?php

use App\Models\User;

beforeEach(function (): void {
    config(['app.demo_login.enabled' => true, 'app.demo_login.email' => 'demo@example.com']);
});

it('logs the visitor in as the designated demo account', function (): void {
    $demo = User::factory()->demo()->create(['email' => 'demo@example.com']);

    $this->post('/demo-login')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($demo);
});

it('returns 404 when the demo login is disabled', function (): void {
    config(['app.demo_login.enabled' => false]);
    User::factory()->demo()->create(['email' => 'demo@example.com']);

    $this->post('/demo-login')->assertNotFound();
    $this->assertGuest();
});

it('returns 404 when enabled without a configured email', function (): void {
    config(['app.demo_login.email' => null]);
    User::factory()->demo()->create(['email' => 'demo@example.com']);

    $this->post('/demo-login')->assertNotFound();
    $this->assertGuest();
});

it('returns 404 when the configured account does not exist', function (): void {
    $this->post('/demo-login')->assertNotFound();
    $this->assertGuest();
});

it('never logs in an ordinary account at the configured email', function (): void {
    User::factory()->create(['email' => 'demo@example.com']);

    $this->post('/demo-login')->assertNotFound();
    $this->assertGuest();
});

it('never logs in an admin, even a demo-flagged one', function (): void {
    User::factory()->demo()->admin()->create(['email' => 'demo@example.com']);

    $this->post('/demo-login')->assertNotFound();
    $this->assertGuest();
});

it('denies admin routes to a demo account promoted outside make-admin', function (): void {
    $user = User::factory()->demo()->admin()->create();

    $this->actingAs($user)->get('/admin/report')->assertForbidden();
});

it('clears the demo flag when the account is promoted via make-admin', function (): void {
    $user = User::factory()->demo()->create(['email' => 'demo@example.com']);

    $this->artisan('user:make-admin', ['email' => 'demo@example.com'])->assertSuccessful();

    $user->refresh();
    expect($user->is_admin)->toBeTrue()
        ->and($user->is_demo)->toBeFalse();

    $this->post('/demo-login')->assertNotFound();
});

it('throttles repeated attempts', function (): void {
    foreach (range(1, 10) as $attempt) {
        $this->post('/demo-login');
    }

    $this->post('/demo-login')->assertTooManyRequests();
});

it('shares only a boolean flag, never the configured email', function (): void {
    config(['app.demo_login.email' => 'secret-demo@example.com']);
    User::factory()->demo()->create(['email' => 'secret-demo@example.com']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('demoLogin', true)
            ->missing('demoLoginEmail')
        );

    expect($this->get('/')->getContent())->not->toContain('secret-demo@example.com');
});

it('hides the demo button when the account is not eligible', function (): void {
    // Enabled + configured, but nobody seeded yet.
    $this->get('/')->assertInertia(fn ($page) => $page->where('demoLogin', false));

    // Seeded but not flagged as a demo account.
    User::factory()->create(['email' => 'demo@example.com']);
    $this->get('/')->assertInertia(fn ($page) => $page->where('demoLogin', false));
});
