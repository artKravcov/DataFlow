<?php

namespace App\Http\Controllers\Internal;

use App\Execution\ExecutionLease;
use App\Graph\GraphInput;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExecutionController extends Controller
{
    public function claim(Request $request, string $id, ExecutionLease $leases, GraphInput $graphInput): JsonResponse
    {
        $input = $this->input($request, false);
        if ($input === null) {
            return $this->error('INVALID_REQUEST', 422);
        }
        try {
            $token = $leases->claim((int) $id, $input['worker_id'], function (Execution $execution) use ($graphInput) {
                $graphInput->validate($execution->snapshot['graph'] ?? [], $execution->organization_id, true);
            });
        } catch (ValidationException $e) {
            return $this->error('SNAPSHOT_UNAVAILABLE', 409);
        }
        if ($token === null) {
            return $this->error('EXECUTION_UNAVAILABLE', 409);
        }
        $execution = Execution::findOrFail($id);

        return response()->json(['data' => ['execution_id' => (string) $id, 'snapshot' => $execution->snapshot, 'lease_token' => $token, 'lease_seconds' => 60]]);
    }

    public function heartbeat(Request $request, string $id, ExecutionLease $leases): JsonResponse
    {
        $input = $this->input($request, true);
        if ($input === null) {
            return $this->error('INVALID_REQUEST', 422);
        }
        if (! $leases->heartbeat((int) $id, $input['worker_id'], $input['lease_token'])) {
            return $this->error('LEASE_UNAVAILABLE', 409);
        }

        return response()->json(['data' => ['lease_seconds' => 60]]);
    }

    private function input(Request $request, bool $heartbeat): ?array
    {
        if (! $request->isJson()) {
            return null;
        }
        $body = json_decode($request->getContent(), true);
        $keys = $heartbeat ? ['worker_id', 'lease_token'] : ['worker_id'];
        if (! is_array($body) || array_diff(array_keys($body), $keys) || count($body) !== count($keys)) {
            return null;
        }
        $rules = ['worker_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/D']];
        if ($heartbeat) {
            $rules['lease_token'] = ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'];
        }
        $validator = Validator::make($body, $rules);

        return $validator->fails() ? null : $validator->validated();
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status);
    }
}
