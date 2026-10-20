<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\FileAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArtifactController extends Controller
{
    public function store(Request $request, string $id): JsonResponse
    {
        $worker = $request->header('X-Worker-Id');
        $token = $request->header('X-Lease-Token');
        $nodeId = $request->header('X-Node-Id');
        $expectedHash = $request->header('X-Content-Sha256');
        if (! is_string($worker) || ! preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $worker) ||
            ! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token) ||
            ! is_string($nodeId) || $nodeId === '' || strlen($nodeId) > 128 ||
            ! is_string($expectedHash) || ! preg_match('/^[a-f0-9]{64}$/D', $expectedHash) ||
            strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'text/csv') {
            return $this->error('INVALID_REQUEST', 422);
        }
        $limit = (int) config('dataflow.max_input_bytes');
        $length = $request->header('Content-Length');
        if ($length !== null && (! ctype_digit($length) || (float) $length > $limit)) {
            return $this->error('PAYLOAD_TOO_LARGE', 413);
        }
        // Reject invalid leases before reading, then recheck under lock after the upload.
        if (! $this->validLease(Execution::find($id), $worker, $token)) {
            return $this->error('LEASE_UNAVAILABLE', 409);
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory('assets');
        $key = 'assets/'.Str::uuid();
        $stream = $request->getContent(true);
        $target = fopen($disk->path($key), 'xb');
        if (! $target) {
            throw new \RuntimeException('Cannot store artifact.');
        }
        $keep = false;
        try {
            $size = 0;
            $hash = hash_init('sha256');
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new \RuntimeException('Cannot read artifact.');
                }
                $size += strlen($chunk);
                if ($size > $limit) {
                    return $this->error('PAYLOAD_TOO_LARGE', 413);
                }
                if (str_contains($chunk, "\0")) {
                    return $this->error('INVALID_CSV', 422);
                }
                hash_update($hash, $chunk);
                if ($chunk !== '' && fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Cannot store artifact.');
                }
            }
            if ($size === 0 || ($length !== null && (int) $length !== $size)) {
                return $this->error('INVALID_CSV', 422);
            }
            if (! fflush($target)) {
                throw new \RuntimeException('Cannot store artifact.');
            }
            fclose($target);
            $target = null;
            $digest = hash_final($hash);
            if (! hash_equals($expectedHash, $digest)) {
                return $this->error('CHECKSUM_MISMATCH', 422);
            }

            return DB::transaction(function () use ($id, $worker, $token, $nodeId, $key, $size, $digest, &$keep) {
                $execution = Execution::whereKey($id)->lockForUpdate()->first();
                if (! $this->validLease($execution, $worker, $token)) {
                    return $this->error('LEASE_UNAVAILABLE', 409);
                }
                $destination = null;
                foreach ($execution->snapshot['graph']['nodes'] ?? [] as $node) {
                    if (($node['id'] ?? null) === $nodeId && ($node['type'] ?? null) === 'csv.destination') {
                        $destination = $node;
                        break;
                    }
                }
                $state = ExecutionNode::where('execution_id', $id)->where('snapshot_node_id', $nodeId)->value('status');
                if (! $destination || $state !== 'running') {
                    return $this->error('DESTINATION_UNAVAILABLE', 409);
                }
                $previous = DB::table('execution_artifacts')->where('execution_id', $id)->where('snapshot_node_id', $nodeId)->first();
                if ($previous) {
                    $asset = FileAsset::whereKey($previous->file_asset_id)->where('organization_id', $execution->organization_id)->where('state', 'ready')->first();
                    if (! $asset || $asset->size_bytes !== $size || ! hash_equals($asset->sha256, $digest) ||
                        ! Storage::disk('local')->exists($asset->storage_key) || Storage::disk('local')->size($asset->storage_key) !== $size) {
                        return $this->error('ARTIFACT_CONFLICT', 409);
                    }

                    return response()->json(['data' => ['asset_id' => (string) $asset->id]], 200);
                }
                $name = $destination['config']['filename'] ?? '';
                if (! is_string($name) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $name)) {
                    return $this->error('DESTINATION_UNAVAILABLE', 409);
                }
                $asset = FileAsset::create(['organization_id' => $execution->organization_id, 'original_name' => $name, 'storage_key' => $key, 'size_bytes' => $size, 'sha256' => $digest, 'state' => 'ready']);
                DB::table('execution_artifacts')->insert(['execution_id' => $id, 'snapshot_node_id' => $nodeId, 'file_asset_id' => $asset->id, 'created_at' => now(), 'updated_at' => now()]);
                // Once DB commit starts, leave the file in place even on an ambiguous commit failure.
                $keep = true;

                return response()->json(['data' => ['asset_id' => (string) $asset->id]], 201);
            });
        } finally {
            if ($target) {
                fclose($target);
            }
            if (! $keep) {
                $disk->delete($key);
            }
        }
    }

    private function validLease(?Execution $execution, string $worker, string $token): bool
    {
        return $execution && $execution->status === 'running' && $execution->worker_id === $worker &&
            $execution->lease_expires_at && $execution->lease_expires_at->gt(now()) && $execution->lease_token_hash &&
            hash_equals($execution->lease_token_hash, hash('sha256', $token));
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }
}
