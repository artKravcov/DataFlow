<?php

namespace App\Http\Controllers\Internal;

use App\Execution\ExecutionEvents;
use App\Execution\ExecutionLease;
use App\Graph\GraphInput;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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

    public function event(Request $request, string $id, ExecutionEvents $events): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error('INVALID_REQUEST', 422);
        }
        $input = json_decode($request->getContent(), true);
        if (! is_array($input) || ! isset($input['type']) || ! is_string($input['type'])) {
            return $this->error('INVALID_REQUEST', 422);
        }
        $types = ['node.started', 'node.succeeded', 'node.failed', 'execution.failed', 'execution.succeeded'];
        if (! in_array($input['type'], $types, true)) {
            return $this->error('INVALID_REQUEST', 422);
        }
        $nodeEvent = str_starts_with($input['type'], 'node.');
        $failed = in_array($input['type'], ['node.failed', 'execution.failed'], true);
        $keys = ['worker_id', 'lease_token', 'event_id', 'sequence', 'type'];
        if ($nodeEvent) {
            $keys[] = 'node_id';
        }
        if ($failed) {
            $keys[] = 'error_code';
        }
        if (array_diff(array_keys($input), $keys) || count($input) !== count($keys) ||
            ! isset($input['event_id'], $input['sequence']) || ! is_string($input['event_id']) ||
            ! Str::isUuid($input['event_id']) || ! is_int($input['sequence']) || $input['sequence'] < 1 || $input['sequence'] > 101) {
            return $this->error('INVALID_REQUEST', 422);
        }
        $rules = [
            'worker_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/D'],
            'lease_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ];
        if ($nodeEvent) {
            $rules['node_id'] = ['required', 'string', 'max:128'];
        }
        if ($failed) {
            $rules['error_code'] = ['required', 'string', 'in:FIELD_MISSING,TYPE_MISMATCH,RESOURCE_LIMIT,TIMEOUT,INVALID_CSV,INTERNAL_ERROR'];
        }
        if (Validator::make($input, $rules)->fails()) {
            return $this->error('INVALID_REQUEST', 422);
        }
        $sequence = $events->accept((int) $id, $input);
        if ($sequence === null) {
            return $this->error('EVENT_CONFLICT', 409);
        }

        return response()->json(['data' => ['accepted_sequence' => $sequence]]);
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
