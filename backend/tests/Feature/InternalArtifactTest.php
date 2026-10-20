<?php

namespace Tests\Feature;

use App\Execution\ExecutionLease;
use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\FileAsset;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InternalArtifactTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_TOKEN = 'test-service-token-0123456789abcdef';

    private function running(): array
    {
        Storage::fake('local');
        config(['dataflow.internal_service_token' => self::SERVICE_TOKEN, 'dataflow.max_input_bytes' => 64]);
        $org = Organization::factory()->create();
        $project = $org->projects()->create(['name' => 'Project']);
        $pipeline = Pipeline::factory()->forProject($project)->create();
        $graph = ['nodes' => [['id' => 'destination', 'type' => 'csv.destination', 'config' => ['filename' => 'result.csv']]], 'edges' => []];
        $execution = Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => User::factory()->create()->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 1, 'status' => 'queued', 'snapshot' => ['revision' => 1, 'graph' => $graph]]);
        ExecutionNode::create(['execution_id' => $execution->id, 'snapshot_node_id' => 'destination', 'status' => 'running']);
        $token = (new ExecutionLease)->claim($execution->id, 'worker-1');

        return [$execution, $token];
    }

    private function upload(Execution $execution, string $token, string $content, string $node = 'destination', string $service = self::SERVICE_TOKEN, ?string $checksum = null): TestResponse
    {
        return $this->call('POST', "/internal/v1/executions/$execution->id/artifacts", [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$service,
            'HTTP_X_WORKER_ID' => 'worker-1',
            'HTTP_X_LEASE_TOKEN' => $token,
            'HTTP_X_NODE_ID' => $node,
            'HTTP_X_CONTENT_SHA256' => $checksum ?? hash('sha256', $content),
            'CONTENT_TYPE' => 'text/csv',
        ], $content);
    }

    public function test_complete_upload_is_private_and_identical_retry_returns_same_asset(): void
    {
        [$execution, $token] = $this->running();
        $content = "id,amount\n1,12\n";
        $id = $this->upload($execution, $token, $content)->assertCreated()->json('data.asset_id');
        $asset = FileAsset::findOrFail($id);
        self::assertSame($execution->organization_id, $asset->organization_id);
        self::assertSame('ready', $asset->state);
        self::assertSame(hash('sha256', $content), $asset->sha256);
        self::assertSame($content, Storage::disk('local')->get($asset->storage_key));
        $this->upload($execution, $token, $content)->assertOk()->assertJsonPath('data.asset_id', $id);
        $this->upload($execution, $token, "id\nother\n")->assertStatus(409);
        $this->assertDatabaseCount('file_assets', 1);
        $this->assertDatabaseCount('execution_artifacts', 1);
        self::assertCount(1, Storage::disk('local')->allFiles('assets'));
    }

    public function test_output_download_requires_membership_in_the_execution_organization(): void
    {
        [$execution, $token] = $this->running();
        $id = $this->upload($execution, $token, "id\n1\n")->assertCreated()->json('data.asset_id');
        $member = User::factory()->create();
        Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $execution->organization_id, 'role' => 'viewer']);
        $path = "/api/v1/organizations/$execution->organization_id/assets/$id/download";
        $this->actingAs($member)->get($path)->assertOk();
        $this->actingAs(User::factory()->create())->get($path)->assertNotFound();
    }

    public function test_rejected_uploads_never_become_assets(): void
    {
        [$execution, $token] = $this->running();
        $this->upload($execution, $token, "a\n", service: 'wrong')->assertUnauthorized();
        $this->upload($execution, str_repeat('b', 64), "a\n")->assertStatus(409);
        $this->upload($execution, $token, "a\n", 'not-destination')->assertStatus(409);
        $this->upload($execution, $token, str_repeat('x', 65))->assertStatus(413);
        $this->upload($execution, $token, "a\0b")->assertStatus(422);
        $this->upload($execution, $token, "a\n", checksum: str_repeat('a', 64))->assertStatus(422)->assertJsonPath('error.code', 'CHECKSUM_MISMATCH');
        $this->upload($execution, $token, '')->assertStatus(422);
        $this->travel(61)->seconds();
        $this->upload($execution, $token, "a\n")->assertStatus(409);
        $this->assertDatabaseCount('file_assets', 0);
        $this->assertDatabaseCount('execution_artifacts', 0);
        self::assertCount(0, Storage::disk('local')->allFiles('assets'));
    }
}
