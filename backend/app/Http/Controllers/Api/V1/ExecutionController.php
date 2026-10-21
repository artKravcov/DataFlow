<?php

namespace App\Http\Controllers\Api\V1;

use App\Graph\GraphInput;
use App\Graph\PipelineGraph;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\Organization;
use App\Models\OutboxMessage;
use App\Models\Pipeline;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExecutionController extends Controller
{
    public function index(Organization $organization, Pipeline $pipeline): JsonResponse
    {
        Gate::authorize('view', $organization);
        $pipeline = Pipeline::where('organization_id', $organization->id)->findOrFail($pipeline->id);
        Gate::authorize('view', $pipeline);
        $items = Execution::where('organization_id', $organization->id)
            ->where('pipeline_id', $pipeline->id)->orderByDesc('id')->limit(10)
            ->get(['id', 'status', 'revision', 'created_at']);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, Organization $organization, Pipeline $pipeline, GraphInput $validator, PipelineGraph $graphs): JsonResponse
    {
        Gate::authorize('view', $organization);
        $pipeline = Pipeline::where('organization_id', $organization->id)->findOrFail($pipeline->id);
        Gate::authorize('update', $pipeline);
        $input = $request->validate(['revision' => 'required|integer|min:0']);
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'A UUID idempotency key is required.']);
        }
        $key = strtolower($key);
        $revision = (int) $input['revision'];

        return DB::transaction(function () use ($request, $organization, $pipeline, $validator, $graphs, $key, $revision) {
            // Serialize same-key requests even when they target different pipelines.
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $existing = Execution::where('organization_id', $organization->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->pipeline_id !== $pipeline->id || $existing->revision !== $revision) {
                    return $this->conflict($request, 'IDEMPOTENCY_CONFLICT', 'Idempotency key was used for a different request');
                }

                return response()->json(['data' => ['id' => $existing->id, 'status' => $existing->status]], 202);
            }
            $project = Project::whereKey($pipeline->project_id)->lockForUpdate()->firstOrFail();
            if ($project->archived_at !== null) {
                abort(404);
            }
            $locked = Pipeline::whereKey($pipeline->id)->where('organization_id', $organization->id)->lockForUpdate()->firstOrFail();
            if ($locked->archived_at !== null) {
                abort(404);
            }
            if ($locked->revision !== $revision) {
                return $this->conflict($request, 'REVISION_CONFLICT', 'Pipeline revision has changed');
            }
            if (Execution::where('pipeline_id', $locked->id)->whereIn('status', ['queued', 'running'])->exists()) {
                return $this->conflict($request, 'EXECUTION_ACTIVE', 'Pipeline already has an active execution');
            }
            $graph = $graphs->graph($locked);
            $validator->validate($graph, $organization->id, true);
            $execution = Execution::create(['organization_id' => $organization->id, 'pipeline_id' => $locked->id, 'requested_by' => $request->user()->id, 'idempotency_key' => $key, 'revision' => $revision, 'status' => 'queued', 'snapshot' => ['revision' => $revision, 'graph' => $graph]]);
            foreach ($graph['nodes'] as $node) {
                ExecutionNode::create(['execution_id' => $execution->id, 'snapshot_node_id' => $node['id'], 'status' => 'pending']);
            }
            $messageId = (string) Str::uuid();
            OutboxMessage::create(['id' => $messageId, 'execution_id' => $execution->id, 'message_type' => 'execution.requested', 'payload' => ['message_version' => 1, 'message_id' => $messageId, 'execution_id' => (string) $execution->id]]);

            return response()->json(['data' => ['id' => $execution->id, 'status' => $execution->status]], 202);
        });
    }

    public function show(Organization $organization, string $execution): JsonResponse
    {
        Gate::authorize('view', $organization);
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/D', $execution), 404);
        $item = Execution::where('organization_id', $organization->id)->findOrFail($execution);
        $nodes = ExecutionNode::where('execution_id', $item->id)->orderBy('id')->get(['snapshot_node_id', 'status']);
        $artifacts = $item->status === 'succeeded' ? DB::table('execution_artifacts')->join('file_assets', 'file_assets.id', '=', 'execution_artifacts.file_asset_id')->where('execution_artifacts.execution_id', $item->id)->where('file_assets.organization_id', $organization->id)->where('file_assets.state', 'ready')->orderBy('execution_artifacts.id')->get(['execution_artifacts.snapshot_node_id', 'file_assets.id as asset_id', 'file_assets.original_name as name', 'file_assets.size_bytes']) : collect();

        return response()->json(['data' => ['id' => $item->id, 'pipeline_id' => $item->pipeline_id, 'revision' => $item->revision, 'status' => $item->status, 'snapshot' => $item->snapshot, 'nodes' => $nodes, 'artifacts' => $artifacts, 'error_code' => $item->error_code, 'created_at' => $item->created_at]]);
    }

    private function conflict(Request $request, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'fields' => (object) [], 'request_id' => $request->attributes->get('request_id')]], 409);
    }
}
