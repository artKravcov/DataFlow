<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Organization;
use App\Models\Membership;
use App\Models\Project;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;
    /**
     * A basic feature test example.
     */
    public function test_owner_can_create_project(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/organizations/{$organization->id}/projects", [
            'name' => 'Test Project',
            'description' => 'This is a test project.',
        ]);

        $response->assertStatus(201);

        $response->assertJsonPath('data.name', 'Test Project');
        $response->assertJsonPath('data.description', 'This is a test project.');

        $this->assertDatabaseHas('projects', [
            'name' => 'Test Project',
            'description' => 'This is a test project.',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_project_creation_returns_validation_envelope_for_missing_name(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/organizations/{$organization->id}/projects", [
            'description' => 'This is a test project.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $response->assertJsonStructure([
            'error' => [
                'fields' => ['name'],
            ],
        ]);
    }

    public function test_user_cannot_view_project_from_another_organization(): void
    {
        $user = User::factory()->create();
        $allowedOrganization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $foreignProject = $foreignOrganization->projects()->create([
            'name' => 'Foreign Project',
            'description' => 'This project belongs to another organization.',
        ]);
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $allowedOrganization->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/organizations/{$allowedOrganization->id}/projects/{$foreignProject->id}");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_viewer_cannot_update_project(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $project = $organization->projects()->create([
            'name' => 'Test Project',
            'description' => 'This is a test project.',
        ]);
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($user)->patchJson("/api/v1/organizations/{$organization->id}/projects/{$project->id}", [
            'name' => 'Updated Project Name',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Test Project',
        ]);
    }

    public function test_owner_can_archive_project(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $project = $organization->projects()->create([
            'name' => 'Test Project',
            'description' => 'This is a test project.',
        ]);
        Membership::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/organizations/{$organization->id}/projects/{$project->id}/archive");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $project->id);
        $this->assertDatabaseMissing('projects', [
            'id' => $project->id,
            'archived_at' => null,
        ]);

        $listResponse = $this->actingAs($user)->getJson("/api/v1/organizations/{$organization->id}/projects");

        $listResponse->assertOk();
        $listResponse->assertJsonCount(0, 'data');
    }
}
