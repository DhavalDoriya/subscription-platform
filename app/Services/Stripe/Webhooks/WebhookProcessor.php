<?php

namespace App\Services\Stripe\Webhooks;

use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Stores an event, then runs its handler exactly once, even if Stripe delivers it several times or in parallel. */
class WebhookProcessor
{
    /**
     * @param  array<string, mixed>  $event
     * @return bool true when this event had already been dealt with
     */
    public function handle(array $event): bool
    {
        $row = WebhookEvent::firstOrCreate(['stripe_event_id' => $event['id']], [
            'type' => $event['type'],
            'account_id' => $event['account'] ?? null,
            'livemode' => (bool) ($event['livemode'] ?? false),
            'payload' => $event,
            'status' => 'pending',
        ]);

        $failure = null;
        $duplicate = false;

        DB::transaction(function () use ($row, $event, &$failure, &$duplicate) {
            $locked = WebhookEvent::lockForUpdate()->findOrFail($row->id);

            if (in_array($locked->status, ['processed', 'ignored'], true)) {
                $duplicate = true;

                return;
            }

            // Event types contain dots, so index the map as an array instead of using a dotted config path.
            $handler = config('stripe_webhooks.handlers', [])[$locked->type] ?? null;

            if (! $handler) {
                $locked->update(['status' => 'ignored', 'processed_at' => now()]);

                return;
            }

            $locked->attempts++;

            try {
                // Nested transaction = savepoint: a handler that fails halfway leaves nothing behind.
                DB::transaction(fn () => app($handler)->handle($event));
                $locked->fill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();
            } catch (Throwable $e) {
                $failure = $e;
                $locked->fill(['status' => 'failed', 'error' => $e->getMessage()])->save();
            }
        });

        if ($failure) {
            throw $failure; // 500 => Stripe redelivers; the stored row says what went wrong
        }

        return $duplicate;
    }
}
