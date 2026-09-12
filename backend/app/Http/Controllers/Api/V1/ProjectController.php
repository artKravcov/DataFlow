<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;

class ProjectController extends Controller
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        $projects = $organization->projects()
            ->whereNull('archived_at')
            ->paginate(25);

        return response()->json([
            'data' => $projects->getCollection()->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'description' => $project->description,
                    'created_at' => $project->created_at,
                    'updated_at' => $project->updated_at,
                ];
            })->values(),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    public function store(StoreProjectRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('create', [Project::class, $organization]);

        $project = $organization->projects()->create($request->validated());

        return response()->json([
            'data' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'created_at' => $project->created_at,
                'updated_at' => $project->updated_at,
            ],
        ], 201);
    }

    public function show(Organization $organization, Project $project): JsonResponse
    {
        Gate::authorize('view', $organization);

        $project = $organization->projects()->findOrFail($project->id);

        Gate::authorize('view', $project);

        return response()->json([
            'data' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'created_at' => $project->created_at,
                'updated_at' => $project->updated_at,
            ],
        ]);
    }

    public function update(UpdateProjectRequest $request, Organization $organization, Project $project): JsonResponse
    {
        Gate::authorize('view', $organization);

        $project = $organization->projects()->findOrFail($project->id);

        Gate::authorize('update', $project);

        $project->update($request->validated());

        return response()->json([
            'data' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'created_at' => $project->created_at,
                'updated_at' => $project->updated_at,
            ],
        ]);
    }

    public function archive(Organization $organization, Project $project): JsonResponse
    {
        Gate::authorize('view', $organization);

        $project = $organization->projects()->findOrFail($project->id);

        Gate::authorize('archive', $project);

        $project->archived_at = now();
        $project->save();

        return response()->json([
            'data' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'created_at' => $project->created_at,
                'updated_at' => $project->updated_at,
                'archived_at' => $project->archived_at,
            ],
        ]);
    }
}
