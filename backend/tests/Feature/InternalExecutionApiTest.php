<?php

namespace Tests\Feature;

use App\Execution\ExecutionLease;
use App\Models\Execution;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InternalExecutionApiTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_TOKEN = 'test-service-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config(['dataflow.internal_service_token' => self::SERVICE_TOKEN]);
    }

    private function queued(): array
    {
        $org = Organization::factory()->create();
        $project = $org->projects()->create(['name' => 'Project']);
        $pipeline = Pipeline::factory()->forProject($project)->create();
        $asset = FileAsset::create(['organization_id' => $org->id, 'original_name' => 'input.csv', 'storage_key' => 'assets/'.Str::uuid(), 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'state' => 'ready']);
        $graph = json_decode(file_get_contents(base_path('../contracts/fixtures/csv-filter-map-v1.json')), true, flags: JSON_THROW_ON_ERROR)['graph'];
        $graph['nodes'][0]['config']['asset_id'] = (string) $asset->id;
        $execution = Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => User::factory()->create()->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 1, 'status' => 'queued', 'snapshot' => ['revision' => 1, 'graph' => $graph]]);

        return [$execution, $asset];
    }

    private function worker(): static
    {
        return $this->withToken(self::SERVICE_TOKEN);
    }

    public function test_worker_authentication_fails_closed_and_browser_session_is_not_enough(): void
    {
        [$execution] = $this->queued();
        $path = "/internal/v1/executions/$execution->id/claim";
        $this->actingAs(User::factory()->create())->postJson($path, ['worker_id' => 'a'])->assertUnauthorized();
        $this->withToken('wrong')->postJson($path, ['worker_id' => 'a'])->assertUnauthorized();
        config(['dataflow.internal_service_token' => '']);
        $this->worker()->postJson($path, ['worker_id' => 'a'])->assertUnauthorized();
        self::assertSame('queued', $execution->fresh()->status);
    }

    public function test_claim_and_heartbeat_are_exclusive_and_do_not_leak_tokens(): void
    {
        [$execution] = $this->queued();
        $path = "/internal/v1/executions/$execution->id";
        $response = $this->worker()->postJson("$path/claim", ['worker_id' => 'worker-1'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.execution_id', (string) $execution->id)->assertJsonPath('data.snapshot.revision', 1);
        $token = $response->json('data.lease_token');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->worker()->postJson("$path/claim", ['worker_id' => 'worker-1'])->assertStatus(409)->assertJsonMissingPath('data.lease_token');
        $this->worker()->postJson("$path/heartbeat", ['worker_id' => 'wrong', 'lease_token' => $token])->assertStatus(409);
        $this->worker()->postJson("$path/heartbeat", ['worker_id' => 'worker-1', 'lease_token' => str_repeat('b', 64)])->assertStatus(409);
        $this->worker()->postJson("$path/heartbeat", ['worker_id' => 'worker-1', 'lease_token' => $token])->assertOk();
        $this->travel(61)->seconds();
        $this->worker()->postJson("$path/heartbeat", ['worker_id' => 'worker-1', 'lease_token' => $token])->assertStatus(409);
        self::assertTrue((new ExecutionLease)->failOneExpired());
        $this->worker()->postJson("$path/claim", ['worker_id' => 'worker-2'])->assertStatus(409);
        self::assertSame('WORKER_LOST', $execution->fresh()->error_code);
    }

    public function test_claim_rechecks_asset_organization_and_readiness_without_changing_execution(): void
    {
        [$execution, $asset] = $this->queued();
        $path = "/internal/v1/executions/$execution->id/claim";
        $asset->update(['state' => 'processing']);
        $this->worker()->postJson($path, ['worker_id' => 'worker-1'])->assertStatus(409)->assertJsonPath('error.code', 'SNAPSHOT_UNAVAILABLE');
        $asset->update(['state' => 'ready', 'organization_id' => Organization::factory()->create()->id]);
        $this->worker()->postJson($path, ['worker_id' => 'worker-1'])->assertStatus(409)->assertJsonMissingPath('data.snapshot');
        self::assertSame('queued', $execution->fresh()->status);
        self::assertNull($execution->fresh()->lease_token_hash);
    }

    public function test_invalid_or_oversized_payloads_are_rejected(): void
    {
        [$execution] = $this->queued();
        $path = "/internal/v1/executions/$execution->id/claim";
        $this->worker()->postJson($path, ['worker_id' => 'worker', 'extra' => 'unexpected'])->assertStatus(422);
        $this->worker()->postJson($path, ['worker_id' => str_repeat('x', 3000)])->assertStatus(413);
        $this->worker()->postJson($path, ['worker_id' => '../unsafe'])->assertStatus(422);
        self::assertSame('queued', $execution->fresh()->status);
    }
}
