<?php

use App\Models\User;

it('returns 404 when the demo login is disabled', function (): void {
    config(['app.demo_login.enabled' => false, 'app.demo_login.email' => 'demo@example.com']);
    User::factory()->create(['email' => 'demo@example.com']);

    $this->post('/demo-login')->assertNotFound();
    $this->assertGuest();
});

it('logs the visitor in as the configured demo account', function (): void {
    config(['app.demo_login.enabled' => true, 'app.demo_login.email' => 'demo@example.com']);
    $demo = User::factory()->create(['email' => 'demo@example.com']);

    $this->post('/demo-login')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($demo);
});

it('returns 404 when the configured account does not exist', function (): void {
    config(['app.demo_login.enabled' => true, 'app.demo_login.email' => 'ghost@example.com']);

    $this->post('/demo-login')->assertNotFound();
    $this->assertGuest();
});

it('refuses to log in when the configured account is an admin', function (): void {
    config(['app.demo_login.enabled' => true, 'app.demo_login.email' => 'admin@example.com']);
    User::factory()->create(['email' => 'admin@example.com', 'is_admin' => true]);

    $this->post('/demo-login')->assertNotFound();
    $this->assertGuest();
});

it('returns 404 when enabled without a configured email', function (): void {
    config(['app.demo_login.enabled' => true, 'app.demo_login.email' => null]);

    $this->post('/demo-login')->assertNotFound();
    $this->assertGuest();
});

it('throttles repeated attempts', function (): void {
    config(['app.demo_login.enabled' => false]);

    foreach (range(1, 10) as $attempt) {
        $this->post('/demo-login');
    }

    $this->post('/demo-login')->assertTooManyRequests();
});

it('shares only a boolean flag, never the configured email', function (): void {
    config(['app.demo_login.enabled' => true, 'app.demo_login.email' => 'secret-demo@example.com']);
    User::factory()->create(['email' => 'secret-demo@example.com']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('demoLogin', true)
            ->missing('demoLoginEmail')
        );

    expect($this->get('/')->getContent())->not->toContain('secret-demo@example.com');
});
