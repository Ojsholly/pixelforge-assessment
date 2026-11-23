<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Generations\EstimateGenerationController;
use App\Http\Controllers\Generations\GenerationController;
use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generate', [GenerationController::class, 'store'])
        ->middleware('throttle:30,1,generations_store')
        ->name('generations.store');
    Route::post('generate/estimate', EstimateGenerationController::class)
        ->middleware('throttle:60,1,generations_estimate')
        ->name('generations.estimate');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/{generation}', [GenerationController::class, 'show'])->name('generations.show');
    Route::post('generations/{generation}/refresh', [GenerationController::class, 'refresh'])
        ->middleware('throttle:30,1,generations_refresh')
        ->name('generations.refresh');
});

require __DIR__.'/settings.php';
