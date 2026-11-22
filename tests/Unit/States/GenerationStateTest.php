<?php

use App\Models\Generation;
use App\States\Generation\Failed;
use App\States\Generation\Processing;
use App\States\Generation\Queued;
use App\States\Generation\Succeeded;
use Tests\TestCase;

uses(TestCase::class);

it('allows valid forward generation state transitions', function (): void {
    $queued = new Generation;
    $queued->status = new Queued($queued);

    expect($queued->status->canTransitionTo(Processing::class))->toBeTrue()
        ->and($queued->status->canTransitionTo(Succeeded::class))->toBeTrue()
        ->and($queued->status->canTransitionTo(Failed::class))->toBeTrue();

    $processing = new Generation;
    $processing->status = new Processing($processing);

    expect($processing->status->canTransitionTo(Succeeded::class))->toBeTrue()
        ->and($processing->status->canTransitionTo(Failed::class))->toBeTrue();
});

it('forbids regressions from terminal states', function (): void {
    $succeeded = new Generation;
    $succeeded->status = new Succeeded($succeeded);

    expect($succeeded->status->canTransitionTo(Queued::class))->toBeFalse()
        ->and($succeeded->status->canTransitionTo(Processing::class))->toBeFalse()
        ->and($succeeded->status->canTransitionTo(Failed::class))->toBeFalse();

    $failed = new Generation;
    $failed->status = new Failed($failed);

    expect($failed->status->canTransitionTo(Queued::class))->toBeFalse()
        ->and($failed->status->canTransitionTo(Processing::class))->toBeFalse()
        ->and($failed->status->canTransitionTo(Succeeded::class))->toBeFalse();
});
