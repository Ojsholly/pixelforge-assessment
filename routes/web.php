<?php

use App\Http\Controllers\Generations\EstimateGenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::post('generate/estimate', EstimateGenerationController::class)
        ->middleware('throttle:60,1')
        ->name('generations.estimate');
});

require __DIR__.'/settings.php';
