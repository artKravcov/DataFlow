<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\FileAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class InputAssetController extends Controller
{
    public function show(Request $request, string $id, string $asset): Response
    {
        $workerId = $request->header('X-Worker-Id');
        $token = $request->header('X-Lease-Token');
        if (! is_string($workerId) || ! preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $workerId) ||
            ! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return response()->json(['error' => ['code' => 'INVALID_REQUEST']], 422);
        }
        $execution = Execution::find($id);
        if (! $execution || $execution->status !== 'running' || $execution->worker_id !== $workerId ||
            ! $execution->lease_expires_at || $execution->lease_expires_at->lte(now()) || ! $execution->lease_token_hash ||
            ! hash_equals($execution->lease_token_hash, hash('sha256', $token))) {
            return response()->json(['error' => ['code' => 'LEASE_UNAVAILABLE']], 409);
        }
        $allowed = false;
        foreach ($execution->snapshot['graph']['nodes'] ?? [] as $node) {
            if (($node['type'] ?? null) === 'csv.source' && ($node['config']['asset_id'] ?? null) === $asset) {
                $allowed = true;
                break;
            }
        }
        $item = $allowed ? FileAsset::where('organization_id', $execution->organization_id)->where('state', 'ready')->find($asset) : null;
        $disk = Storage::disk('local');
        if (! $item || $item->size_bytes > config('dataflow.max_input_bytes') || ! $disk->exists($item->storage_key) ||
            $disk->size($item->storage_key) !== $item->size_bytes) {
            return response()->json(['error' => ['code' => 'ASSET_UNAVAILABLE']], 404);
        }

        return $disk->response($item->storage_key, null, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Content-Length' => (string) $item->size_bytes]);
    }
}
