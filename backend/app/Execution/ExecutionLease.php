<?php

namespace App\Execution;

use App\Models\Execution;
use App\Models\ExecutionNode;
use Illuminate\Support\Facades\DB;

/** Serializes execution lease state transitions. */
class ExecutionLease
{
    private const SECONDS = 60;

    public function claim(int $executionId, string $workerId, ?callable $validate = null): ?string
    {
        if ($workerId === '' || strlen($workerId) > 128) {
            throw new \InvalidArgumentException('Invalid worker ID');
        }

        return DB::transaction(function () use ($executionId, $workerId, $validate) {
            $execution = Execution::whereKey($executionId)->lockForUpdate()->first();
            if (! $execution || $execution->status !== 'queued') {
                return null;
            }
            if ($validate) {
                $validate($execution);
            }
            $token = bin2hex(random_bytes(32));
            $execution->update([
                'status' => 'running',
                'worker_id' => $workerId,
                'lease_token_hash' => hash('sha256', $token),
                'lease_expires_at' => now()->addSeconds(self::SECONDS),
            ]);

            return $token;
        });
    }

    public function heartbeat(int $executionId, string $workerId, string $token): bool
    {
        return DB::transaction(function () use ($executionId, $workerId, $token) {
            $execution = Execution::whereKey($executionId)->lockForUpdate()->first();
            if (! $execution || $execution->status !== 'running' || $execution->worker_id !== $workerId ||
                ! $execution->lease_expires_at || $execution->lease_expires_at->lte(now()) ||
                ! $execution->lease_token_hash || ! hash_equals($execution->lease_token_hash, hash('sha256', $token))) {
                return false;
            }
            $execution->update(['lease_expires_at' => now()->addSeconds(self::SECONDS)]);

            return true;
        });
    }

    public function failOneExpired(): bool
    {
        return DB::transaction(function () {
            $execution = Execution::where('status', 'running')->where('lease_expires_at', '<=', now())
                ->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $execution) {
                return false;
            }
            ExecutionNode::where('execution_id', $execution->id)->where('status', 'running')->update(['status' => 'failed']);
            ExecutionNode::where('execution_id', $execution->id)->where('status', 'pending')->update(['status' => 'skipped']);
            $execution->update(['status' => 'failed', 'error_code' => 'WORKER_LOST', 'lease_token_hash' => null]);

            return true;
        });
    }
}
