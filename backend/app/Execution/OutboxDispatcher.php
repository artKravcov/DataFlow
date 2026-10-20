<?php

namespace App\Execution;

use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Throwable;

class OutboxDispatcher
{
    public function __construct(private StreamPublisher $publisher) {}

    /** Publish at most one ready row. Concurrent dispatchers skip rows already locked. */
    public function dispatchOne(): string
    {
        return DB::transaction(function () {
            $message = OutboxMessage::where('message_type', 'execution.requested')->whereNull('published_at')
                ->where(function ($query) {
                    $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('created_at')->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $message) {
                return 'idle';
            }
            $attempts = min((int) $message->attempts + 1, 1000000);
            try {
                if (($message->payload['message_id'] ?? null) !== $message->id || ($message->payload['execution_id'] ?? null) !== (string) $message->execution_id) {
                    throw new \UnexpectedValueException('Outbox envelope does not match its row.');
                }
                $this->publisher->publish($message->payload);
            } catch (Throwable $e) {
                // The result may be unknown, so another publication may duplicate this message.
                $message->attempts = $attempts;
                $message->next_attempt_at = now()->addSeconds(min(300, 2 ** min($attempts, 8)));
                $message->save();

                return 'retry';
            }
            $message->attempts = $attempts;
            $message->published_at = now();
            $message->next_attempt_at = null;
            $message->save();

            return 'published';
        });
    }
}
