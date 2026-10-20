<?php

namespace App\Execution;

use App\Models\Execution;
use App\Models\ExecutionNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExecutionEvents
{
    /** Returns the accepted sequence, or null if the event conflicts with state or lease. */
    public function accept(int $executionId, array $input): ?int
    {
        return DB::transaction(function () use ($executionId, $input) {
            $execution = Execution::whereKey($executionId)->lockForUpdate()->first();
            if (! $execution || $execution->worker_id !== $input['worker_id'] || ! $execution->lease_token_hash ||
                ! hash_equals($execution->lease_token_hash, hash('sha256', $input['lease_token']))) {
                return null;
            }
            $event = [
                'event_id' => strtolower($input['event_id']),
                'sequence' => $input['sequence'],
                'type' => $input['type'],
                'node_id' => $input['node_id'] ?? null,
                'error_code' => $input['error_code'] ?? null,
            ];
            $hash = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
            $existing = DB::table('execution_events')->where('execution_id', $executionId)->where('event_id', $event['event_id'])->first();
            $active = $execution->status === 'running' && $execution->lease_expires_at && $execution->lease_expires_at->gt(now());
            if ($existing) {
                $terminalConfirmation = ($execution->status === 'failed' && $existing->type === 'execution.failed') ||
                    ($execution->status === 'succeeded' && $existing->type === 'execution.succeeded');

                return ($active || $terminalConfirmation) && hash_equals($existing->payload_hash, $hash) ? $existing->sequence : null;
            }
            if (! $active) {
                return null;
            }
            $last = (int) DB::table('execution_events')->where('execution_id', $executionId)->max('sequence');
            if ($event['sequence'] !== $last + 1 || ! $this->transition($execution, $event)) {
                return null;
            }
            DB::table('execution_events')->insert([
                'execution_id' => $executionId,
                'event_id' => $event['event_id'],
                'sequence' => $event['sequence'],
                'type' => $event['type'],
                'payload_hash' => $hash,
                'created_at' => now(),
            ]);

            return $event['sequence'];
        });
    }

    private function transition(Execution $execution, array $event): bool
    {
        if ($event['type'] === 'execution.succeeded') {
            $snapshotNodes = $execution->snapshot['graph']['nodes'] ?? [];
            $nodes = ExecutionNode::where('execution_id', $execution->id)->get();
            if (! $snapshotNodes || $nodes->count() !== count($snapshotNodes) || $nodes->contains(fn ($node) => $node->status !== 'succeeded')) {
                return false;
            }
            $destinations = 0;
            foreach ($snapshotNodes as $snapshotNode) {
                if (($snapshotNode['type'] ?? null) === 'csv.destination') {
                    $destinations++;
                    if (! $this->artifactReady($execution, $snapshotNode['id'])) {
                        return false;
                    }
                }
            }
            if ($destinations === 0) {
                return false;
            }
            $execution->update(['status' => 'succeeded']);

            return true;
        }
        if ($event['type'] === 'execution.failed') {
            ExecutionNode::where('execution_id', $execution->id)->where('status', 'running')->update(['status' => 'failed']);
            ExecutionNode::where('execution_id', $execution->id)->where('status', 'pending')->update(['status' => 'skipped']);
            $execution->update(['status' => 'failed', 'error_code' => $event['error_code']]);

            return true;
        }
        $node = ExecutionNode::where('execution_id', $execution->id)->where('snapshot_node_id', $event['node_id'])->first();
        if (! $node) {
            return false;
        }
        if ($event['type'] === 'node.started') {
            if ($node->status !== 'pending' || ExecutionNode::where('execution_id', $execution->id)->where('status', 'running')->exists() ||
                ExecutionNode::where('execution_id', $execution->id)->where('status', 'failed')->exists()) {
                return false;
            }
            foreach ($execution->snapshot['graph']['edges'] ?? [] as $edge) {
                if ($edge['target'] === $node->snapshot_node_id && ! ExecutionNode::where('execution_id', $execution->id)->where('snapshot_node_id', $edge['source'])->where('status', 'succeeded')->exists()) {
                    return false;
                }
            }
            $node->update(['status' => 'running']);

            return true;
        }
        if ($node->status !== 'running') {
            return false;
        }
        if ($event['type'] === 'node.succeeded') {
            foreach ($execution->snapshot['graph']['nodes'] ?? [] as $snapshotNode) {
                if ($snapshotNode['id'] === $node->snapshot_node_id && $snapshotNode['type'] === 'csv.destination') {
                    if (! $this->artifactReady($execution, $node->snapshot_node_id)) {
                        return false;
                    }
                }
            }
            $node->update(['status' => 'succeeded']);

            return true;
        }
        if ($event['type'] === 'node.failed') {
            $node->update(['status' => 'failed']);

            return true;
        }

        return false;
    }

    private function artifactReady(Execution $execution, string $nodeId): bool
    {
        $asset = DB::table('execution_artifacts')
            ->join('file_assets', 'file_assets.id', '=', 'execution_artifacts.file_asset_id')
            ->where('execution_artifacts.execution_id', $execution->id)
            ->where('execution_artifacts.snapshot_node_id', $nodeId)
            ->where('file_assets.organization_id', $execution->organization_id)
            ->where('file_assets.state', 'ready')
            ->select('file_assets.storage_key', 'file_assets.sha256', 'file_assets.size_bytes')->first();
        $disk = Storage::disk('local');
        if (! $asset || $asset->size_bytes > config('dataflow.max_input_bytes') ||
            ! $disk->exists($asset->storage_key) || $disk->size($asset->storage_key) !== $asset->size_bytes) {
            return false;
        }
        $digest = hash_file('sha256', $disk->path($asset->storage_key));

        return is_string($digest) && hash_equals($asset->sha256, $digest);
    }
}
