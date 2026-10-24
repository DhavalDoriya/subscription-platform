<?php

use App\Enums\DeclineType;
use App\Models\DunningEvent;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use Illuminate\Support\Str;

it('FR-R4 an attempt records number, decline, result and the next retry', function () {
    $attempt = PaymentAttempt::factory()->create([
        'attempt_number' => 2,
        'status' => 'failed',
        'decline_code' => 'insufficient_funds',
        'decline_type' => DeclineType::Soft,
        'next_retry_at' => now()->addDays(3),
    ])->fresh();

    expect(Str::isUuid($attempt->id))->toBeTrue()
        ->and($attempt->attempt_number)->toBe(2)
        ->and($attempt->decline_type)->toBe(DeclineType::Soft)
        ->and($attempt->next_retry_at)->not->toBeNull()
        ->and($attempt->invoice)->toBeInstanceOf(Invoice::class);

    $attempt->delete();
    expect(PaymentAttempt::withTrashed()->find($attempt->id))->not->toBeNull();
});

it('FR-R6 a dunning event keeps its payload', function () {
    $event = DunningEvent::factory()->create(['type' => 'retry_scheduled', 'payload' => ['days' => 3]])->fresh();

    expect($event->type)->toBe('retry_scheduled')->and($event->payload)->toBe(['days' => 3]);
    $event->delete();
    expect(DunningEvent::withTrashed()->find($event->id))->not->toBeNull();
});

it('FR-R7 an invoice tracks its dunning state', function () {
    $invoice = Invoice::factory()->create(['dunning_status' => 'retrying', 'next_retry_at' => now()->addDay(), 'first_failed_at' => now()])->fresh();

    expect($invoice->dunning_status)->toBe('retrying')
        ->and($invoice->next_retry_at)->not->toBeNull()
        ->and($invoice->first_failed_at)->not->toBeNull();
});
