<?php

use App\Models\Generation;
use App\States\Generation\Failed;
use App\States\Generation\Processing;
use App\States\Generation\Queued;
use App\States\Generation\Succeeded;
use Tests\TestCase;

uses(TestCase::class);

it('allows valid forward generation state transitions', function (): void {
    $queued = new Generation(['status' => Queued::class]);

    expect($queued->status->canTransitionTo(Processing::class))->toBeTrue()
        ->and($queued->status->canTransitionTo(Succeeded::class))->toBeTrue()
        ->and($queued->status->canTransitionTo(Failed::class))->toBeTrue();

    $processing = new Generation(['status' => Processing::class]);

    expect($processing->status->canTransitionTo(Succeeded::class))->toBeTrue()
        ->and($processing->status->canTransitionTo(Failed::class))->toBeTrue();
});

it('forbids regressions from terminal states', function (): void {
    $succeeded = new Generation(['status' => Succeeded::class]);

    expect($succeeded->status->canTransitionTo(Queued::class))->toBeFalse()
        ->and($succeeded->status->canTransitionTo(Processing::class))->toBeFalse()
        ->and($succeeded->status->canTransitionTo(Failed::class))->toBeFalse();

    $failed = new Generation(['status' => Failed::class]);

    expect($failed->status->canTransitionTo(Queued::class))->toBeFalse()
        ->and($failed->status->canTransitionTo(Processing::class))->toBeFalse()
        ->and($failed->status->canTransitionTo(Succeeded::class))->toBeFalse();
});
