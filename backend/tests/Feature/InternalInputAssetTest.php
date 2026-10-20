<?php

namespace Tests\Feature;

use App\Execution\ExecutionLease;
use App\Models\Execution;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InternalInputAssetTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_TOKEN = 'test-service-token-0123456789abcdef';

    private function setupExecution(): array
    {
        Storage::fake('local');
        config(['dataflow.internal_service_token' => self::SERVICE_TOKEN]);
        $org = Organization::factory()->create();
        $project = $org->projects()->create(['name' => 'Project']);
        $pipeline = Pipeline::factory()->forProject($project)->create();
        $content = "id,status\n1,paid\n";
        Storage::disk('local')->put('assets/input', $content);
        $asset = FileAsset::create(['organization_id' => $org->id, 'original_name' => 'input.csv', 'storage_key' => 'assets/input', 'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content), 'state' => 'ready']);
        $graph = ['nodes' => [['id' => 'source', 'type' => 'csv.source', 'config' => ['asset_id' => (string) $asset->id]]], 'edges' => []];
        $execution = Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => User::factory()->create()->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 1, 'status' => 'queued', 'snapshot' => ['revision' => 1, 'graph' => $graph]]);
        $token = (new ExecutionLease)->claim($execution->id, 'worker-1');

        return [$execution, $asset, $token, $content];
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.self::SERVICE_TOKEN, 'X-Worker-Id' => 'worker-1', 'X-Lease-Token' => $token];
    }

    public function test_only_authenticated_worker_with_live_lease_can_read_snapshot_input(): void
    {
        [$execution, $asset, $token, $content] = $this->setupExecution();
        $path = "/internal/v1/executions/$execution->id/assets/$asset->id/content";
        $this->get($path)->assertUnauthorized();
        $this->withHeaders($this->headers(str_repeat('b', 64)))->get($path)->assertStatus(409);
        $response = $this->withHeaders($this->headers($token))->get($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame($content, $response->streamedContent());
        $this->travel(61)->seconds();
        $this->withHeaders($this->headers($token))->get($path)->assertStatus(409);
    }

    public function test_only_ready_file_of_same_organization_in_snapshot_is_accessible(): void
    {
        [$execution, $asset, $token] = $this->setupExecution();
        $path = "/internal/v1/executions/$execution->id/assets";
        $other = FileAsset::create(['organization_id' => $execution->organization_id, 'original_name' => 'other.csv', 'storage_key' => 'assets/other', 'size_bytes' => 2, 'sha256' => str_repeat('a', 64), 'state' => 'ready']);
        Storage::disk('local')->put('assets/other', 'hi');
        $this->withHeaders($this->headers($token))->get("$path/$other->id/content")->assertNotFound();
        $asset->update(['organization_id' => Organization::factory()->create()->id]);
        $this->withHeaders($this->headers($token))->get("$path/$asset->id/content")->assertNotFound();
        $asset->update(['organization_id' => $execution->organization_id, 'state' => 'processing']);
        $this->withHeaders($this->headers($token))->get("$path/$asset->id/content")->assertNotFound();
        $asset->update(['state' => 'ready']);
        Storage::disk('local')->put($asset->storage_key, 'unexpected size');
        $this->withHeaders($this->headers($token))->get("$path/$asset->id/content")->assertNotFound();
        Storage::disk('local')->delete($asset->storage_key);
        $this->withHeaders($this->headers($token))->get("$path/$asset->id/content")->assertNotFound();
    }
}
