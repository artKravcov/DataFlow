<?php

namespace App\Execution;

use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueReconciler
{
    /** Inspect one unclaimed execution; return idle, waiting, republish, or timed_out. */
    public function reconcileOne(): string
    {
        return DB::transaction(function () {
            $execution = Execution::where('status', 'queued')->where('created_at', '<=', now()->subSeconds(30))
                ->where(fn ($query) => $query->where('created_at', '<=', now()->subMinutes(10))->orWhereNull('queue_next_check_at')->orWhere('queue_next_check_at', '<=', now()))
                ->orderBy('created_at')->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $execution) {
                return 'idle';
            }
            if ($execution->created_at->lte(now()->subMinutes(10))) {
                ExecutionNode::where('execution_id', $execution->id)->where('status', 'pending')->update(['status' => 'skipped']);
                $execution->update(['status' => 'failed', 'error_code' => 'QUEUE_TIMEOUT', 'queue_next_check_at' => null]);

                return 'timed_out';
            }
            $checks = min((int) $execution->queue_checks + 1, 1000000);
            $execution->update(['queue_checks' => $checks, 'queue_next_check_at' => now()->addSeconds(min(60, 2 ** min($checks, 6)))]);
            $message = OutboxMessage::where('execution_id', $execution->id)->where('message_type', 'execution.requested')
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
            if (! $message) {
                $id = (string) Str::uuid();
                OutboxMessage::create(['id' => $id, 'execution_id' => $execution->id, 'message_type' => 'execution.requested', 'payload' => ['message_version' => 1, 'message_id' => $id, 'execution_id' => (string) $execution->id]]);

                return 'republish';
            }
            if ($message->published_at === null) {
                return 'waiting';
            }
            $message->update(['published_at' => null, 'next_attempt_at' => null]);

            return 'republish';
        });
    }
}
