<?php

namespace App\Http\Controllers\Api\V1;

use App\Graph\GraphInput;
use App\Graph\NodeRegistry;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PipelineController extends Controller
{
    public function types(NodeRegistry $registry): JsonResponse
    {
        return response()->json(['data' => $registry->catalog()]);
    }

    private function project(Organization $organization, Project $project): Project
    {
        Gate::authorize('view', $organization);
        $project = $organization->projects()->whereNull('archived_at')->findOrFail($project->id);
        Gate::authorize('view', $project);

        return $project;
    }

    private function pipeline(Organization $organization, Pipeline $pipeline): Pipeline
    {
        Gate::authorize('view', $organization);
        $pipeline = $organization->pipelines()->whereNull('archived_at')->findOrFail($pipeline->id);
        Gate::authorize('view', $pipeline);

        return $pipeline;
    }

    public function index(Organization $organization, Project $project): JsonResponse
    {
        $project = $this->project($organization, $project);
        $page = $project->pipelines()->whereNull('archived_at')->orderBy('id')->paginate(25);

        return response()->json(['data' => $page->getCollection()->map(fn ($pipeline) => $this->present($pipeline, false))->values(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function store(Request $request, Organization $organization, Project $project): JsonResponse
    {
        $project = $this->project($organization, $project);
        Gate::authorize('update', $project);
        $input = $request->validate(['name' => 'required|string|max:255']);
        $pipeline = new Pipeline($input);
        $pipeline->organization_id = $organization->id;
        $pipeline->project_id = $project->id;
        $pipeline->save();
        $pipeline->refresh();

        return response()->json(['data' => $this->present($pipeline)], 201);
    }

    public function show(Organization $organization, Pipeline $pipeline): JsonResponse
    {
        $pipeline = $this->pipeline($organization, $pipeline);

        return DB::transaction(function () use ($pipeline) {
            $locked = Pipeline::query()->whereKey($pipeline->id)->whereNull('archived_at')->sharedLock()->firstOrFail();

            return response()->json(['data' => $this->present($locked)]);
        });
    }

    public function update(Request $request, Organization $organization, Pipeline $pipeline): JsonResponse
    {
        $pipeline = $this->pipeline($organization, $pipeline);
        Gate::authorize('update', $pipeline);
        $pipeline->update($request->validate(['name' => 'required|string|max:255']));

        return response()->json(['data' => $this->present($pipeline, false)]);
    }

    public function saveGraph(Request $request, Organization $organization, Pipeline $pipeline, GraphInput $validator): JsonResponse
    {
        $pipeline = $this->pipeline($organization, $pipeline);
        Gate::authorize('update', $pipeline);
        $input = $request->validate(['revision' => 'required|integer|min:0', 'graph' => 'required|array']);
        $diagnostics = $validator->validate($input['graph'], $organization->id);

        return DB::transaction(function () use ($pipeline, $input, $diagnostics, $request) {
            $locked = Pipeline::query()->whereKey($pipeline->id)->lockForUpdate()->firstOrFail();
            if ($locked->archived_at !== null) {
                abort(404);
            }
            if ($locked->revision !== (int) $input['revision']) {
                return $this->conflict($request);
            }
            $locked->edges()->delete();
            $locked->nodes()->delete();
            foreach ($input['graph']['nodes'] as $node) {
                $locked->nodes()->create(['node_id' => $node['id'], 'type' => $node['type'], 'type_version' => $node['type_version'], 'position' => $node['position'], 'config' => $node['config'], 'schema_hints' => $node['schema_hints'] ?? null]);
            }
            foreach ($input['graph']['edges'] as $edge) {
                $locked->edges()->create(['edge_id' => $edge['id'], 'source_node_id' => $edge['source'], 'source_port' => $edge['source_port'], 'target_node_id' => $edge['target'], 'target_port' => $edge['target_port']]);
            }
            $locked->revision++;
            $locked->schema_version = $input['graph']['schema_version'];
            $locked->save();

            return response()->json(['data' => $this->present($locked), 'diagnostics' => $diagnostics]);
        });
    }

    public function validateGraph(Request $request, Organization $organization, Pipeline $pipeline, GraphInput $validator): JsonResponse
    {
        $pipeline = $this->pipeline($organization, $pipeline);
        $input = $request->validate(['revision' => 'required|integer|min:0']);

        return DB::transaction(function () use ($pipeline, $input, $validator, $organization, $request) {
            $locked = Pipeline::query()->whereKey($pipeline->id)->whereNull('archived_at')->sharedLock()->firstOrFail();
            if ($locked->revision !== (int) $input['revision']) {
                return $this->conflict($request);
            }
            $result = $validator->validate($this->graph($locked), $organization->id, true);

            return response()->json(['data' => ['revision' => $locked->revision, 'valid' => true, 'warnings' => $result['warnings']]]);
        });
    }

    public function archive(Organization $organization, Pipeline $pipeline): JsonResponse
    {
        $pipeline = $this->pipeline($organization, $pipeline);
        Gate::authorize('update', $pipeline);
        DB::transaction(function () use ($pipeline) {
            $locked = Pipeline::query()->whereKey($pipeline->id)->lockForUpdate()->firstOrFail();
            $locked->archived_at = now();
            $locked->save();
        });

        return response()->json(['data' => ['id' => $pipeline->id, 'archived' => true]]);
    }

    private function conflict(Request $request): JsonResponse
    {
        return response()->json(['error' => ['code' => 'REVISION_CONFLICT', 'message' => 'Pipeline revision has changed', 'fields' => (object) [], 'request_id' => $request->attributes->get('request_id')]], 409);
    }

    private function graph(Pipeline $pipeline): array
    {
        $nodes = $pipeline->nodes()->orderBy('id')->get()->map(function ($node) {
            $result = ['id' => $node->node_id, 'type' => $node->type, 'type_version' => $node->type_version, 'position' => $node->position, 'config' => $node->config];
            if ($node->schema_hints !== null) {
                $result['schema_hints'] = $node->schema_hints;
            }

            return $result;
        })->all();
        $edges = $pipeline->edges()->orderBy('id')->get()->map(fn ($edge) => ['id' => $edge->edge_id, 'source' => $edge->source_node_id, 'source_port' => $edge->source_port, 'target' => $edge->target_node_id, 'target_port' => $edge->target_port])->all();

        return ['schema_version' => $pipeline->schema_version, 'nodes' => $nodes, 'edges' => $edges];
    }

    private function present(Pipeline $pipeline, bool $withGraph = true): array
    {
        $data = ['id' => $pipeline->id, 'organization_id' => $pipeline->organization_id, 'project_id' => $pipeline->project_id, 'name' => $pipeline->name, 'revision' => $pipeline->revision, 'created_at' => $pipeline->created_at, 'updated_at' => $pipeline->updated_at];
        if ($withGraph) {
            $data['graph'] = $this->graph($pipeline);
            foreach ($data['graph']['nodes'] as &$node) {
                $node['config'] = (object) $node['config'];
            }
        }

        return $data;
    }
}
