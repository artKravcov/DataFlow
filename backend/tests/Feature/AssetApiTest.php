<?php

namespace Tests\Feature;

use App\Models\FileAsset;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_download_and_graph_respect_organization_and_roles(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        Membership::factory()->create(['user_id' => $owner->id, 'organization_id' => $org->id, 'role' => 'owner']);
        Membership::factory()->create(['user_id' => $owner->id, 'organization_id' => $other->id, 'role' => 'owner']);
        Membership::factory()->create(['user_id' => $viewer->id, 'organization_id' => $org->id, 'role' => 'viewer']);
        $url = "/api/v1/organizations/$org->id/assets";
        $this->actingAs($owner);
        $response = $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('data.csv', "name\nAlice\n")])->assertCreated()->assertJsonMissingPath('data.storage_key');
        $id = $response->json('data.id');
        $asset = FileAsset::findOrFail($id);
        Storage::disk('local')->assertExists($asset->storage_key);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.name', 'data.csv')->assertJsonMissingPath('data.0.sha256');
        $this->get("$url/$id/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertStreamedContent("name\nAlice\n");
        $this->getJson("/api/v1/organizations/$other->id/assets/$id/download")->assertNotFound();
        $project = $org->projects()->create(['name' => 'P']);
        $pipeline = Pipeline::factory()->forProject($project)->create();
        $graph = json_decode(file_get_contents(base_path('../contracts/fixtures/csv-filter-map-v1.json')), true, flags: JSON_THROW_ON_ERROR)['graph'];
        $graph['nodes'][0]['config']['asset_id'] = (string) $id;
        $this->putJson("/api/v1/organizations/$org->id/pipelines/$pipeline->id/graph", ['revision' => 0, 'graph' => $graph])->assertOk();
        $otherProject = $other->projects()->create(['name' => 'P']);
        $otherPipeline = Pipeline::factory()->forProject($otherProject)->create();
        $this->putJson("/api/v1/organizations/$other->id/pipelines/$otherPipeline->id/graph", ['revision' => 0, 'graph' => $graph])->assertUnprocessable();
        $asset->update(['state' => 'processing']);
        $this->putJson("/api/v1/organizations/$org->id/pipelines/$pipeline->id/graph", ['revision' => 1, 'graph' => $graph])->assertUnprocessable();
        $asset->update(['state' => 'ready']);
        $this->actingAs($viewer)->get("$url/$id/download")->assertOk();
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('x.csv', 'x')])->assertForbidden();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->getJson("$url/$id/download")->assertNotFound();
    }

    public function test_upload_rejects_invalid_and_oversized_files_without_persisting_them(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id, 'role' => 'editor']);
        $this->actingAs($user);
        $url = "/api/v1/organizations/$org->id/assets";
        config(['dataflow.max_input_bytes' => 5]);
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('data.csv', '123456')])->assertStatus(413);
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('data.csv', "a\0b")])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('data.txt', 'abc')])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('data.csv', '')])->assertUnprocessable();
        $this->assertDatabaseCount('file_assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('assets'));
    }
}
