<?php

use App\Models\WebhookEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('generations:reconcile-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('model:prune', ['--model' => WebhookEvent::class])
    ->daily();

Schedule::command('queue:prune-batches', ['--hours' => 48])
    ->daily();
