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
use Tests\TestCase;

class InternalExecutionEventsTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_TOKEN = 'test-service-token-0123456789abcdef';

    private function running(): array
    {
        config(['dataflow.internal_service_token' => self::SERVICE_TOKEN]);
        $org = Organization::factory()->create();
        $project = $org->projects()->create(['name' => 'Project']);
        $pipeline = Pipeline::factory()->forProject($project)->create();
        $graph = ['nodes' => [['id' => 'source', 'type' => 'csv.source'], ['id' => 'destination', 'type' => 'csv.destination', 'config' => ['filename' => 'result.csv']]], 'edges' => [['source' => 'source', 'target' => 'destination']]];
        $execution = Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => User::factory()->create()->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 1, 'status' => 'queued', 'snapshot' => ['revision' => 1, 'graph' => $graph]]);
        foreach ($graph['nodes'] as $node) {
            ExecutionNode::create(['execution_id' => $execution->id, 'snapshot_node_id' => $node['id'], 'status' => 'pending']);
        }
        $token = (new ExecutionLease)->claim($execution->id, 'worker-1');

        return [$execution, $token];
    }

    private function event(string $token, int $sequence, string $type, array $extra = []): array
    {
        return ['worker_id' => 'worker-1', 'lease_token' => $token, 'event_id' => (string) Str::uuid(), 'sequence' => $sequence, 'type' => $type] + $extra;
    }

    public function test_replay_sequence_and_node_order_are_checked(): void
    {
        [$execution, $token] = $this->running();
        $path = "/internal/v1/executions/$execution->id/events";
        $start = $this->event($token, 1, 'node.started', ['node_id' => 'source']);
        $this->postJson($path, $start)->assertUnauthorized();
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $this->event($token, 1, 'node.started', ['node_id' => 'destination']))->assertStatus(409);
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $start)->assertOk()->assertJsonPath('data.accepted_sequence', 1);
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $start)->assertOk()->assertJsonPath('data.accepted_sequence', 1);
        $changed = $start;
        $changed['node_id'] = 'destination';
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $changed)->assertStatus(409);
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $this->event($token, 3, 'node.succeeded', ['node_id' => 'source']))->assertStatus(409);
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $this->event($token, 2, 'node.succeeded', ['node_id' => 'source']))->assertOk();
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $this->event($token, 3, 'node.started', ['node_id' => 'destination']))->assertOk();
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $this->event($token, 4, 'node.succeeded', ['node_id' => 'destination']))->assertStatus(409);
        $this->assertDatabaseCount('execution_events', 3);
    }

    public function test_failure_is_terminal_repeatable_and_preserves_node_outcomes(): void
    {
        [$execution, $token] = $this->running();
        $path = "/internal/v1/executions/$execution->id/events";
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $this->event($token, 1, 'node.started', ['node_id' => 'source']))->assertOk();
        $failure = $this->event($token, 2, 'execution.failed', ['error_code' => 'FIELD_MISSING']);
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $failure)->assertOk();
        $this->travel(61)->seconds();
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $failure)->assertOk()->assertJsonPath('data.accepted_sequence', 2);
        $other = $failure;
        $other['error_code'] = 'TIMEOUT';
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $other)->assertStatus(409);
        $other = $failure;
        $other['lease_token'] = str_repeat('a', 64);
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $other)->assertStatus(409);
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $this->event($token, 3, 'node.started', ['node_id' => 'destination']))->assertStatus(409);
        self::assertSame('failed', $execution->fresh()->status);
        self::assertSame('FIELD_MISSING', $execution->fresh()->error_code);
        $this->assertDatabaseHas('execution_nodes', ['execution_id' => $execution->id, 'snapshot_node_id' => 'source', 'status' => 'failed']);
        $this->assertDatabaseHas('execution_nodes', ['execution_id' => $execution->id, 'snapshot_node_id' => 'destination', 'status' => 'skipped']);
        $this->assertDatabaseCount('execution_events', 2);
    }

    public function test_success_requires_verified_artifact_and_final_response_is_replayable(): void
    {
        Storage::fake('local');
        [$execution, $token] = $this->running();
        $path = "/internal/v1/executions/$execution->id/events";
        $send = fn (array $event) => $this->withToken(self::SERVICE_TOKEN)->postJson($path, $event);
        $send($this->event($token, 1, 'node.started', ['node_id' => 'source']))->assertOk();
        $send($this->event($token, 2, 'node.succeeded', ['node_id' => 'source']))->assertOk();
        $send($this->event($token, 3, 'node.started', ['node_id' => 'destination']))->assertOk();
        $nodeSuccess = $this->event($token, 4, 'node.succeeded', ['node_id' => 'destination']);
        $send($nodeSuccess)->assertStatus(409);
        $send($this->event($token, 4, 'execution.succeeded'))->assertStatus(409);
        $content = "id,amount\n1,12\n";
        $upload = $this->call('POST', "/internal/v1/executions/$execution->id/artifacts", [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::SERVICE_TOKEN,
            'HTTP_X_WORKER_ID' => 'worker-1', 'HTTP_X_LEASE_TOKEN' => $token,
            'HTTP_X_NODE_ID' => 'destination', 'HTTP_X_CONTENT_SHA256' => hash('sha256', $content),
            'CONTENT_TYPE' => 'text/csv',
        ], $content)->assertCreated();
        $asset = FileAsset::findOrFail($upload->json('data.asset_id'));
        Storage::disk('local')->put($asset->storage_key, str_repeat('x', strlen($content)));
        $send($nodeSuccess)->assertStatus(409);
        Storage::disk('local')->put($asset->storage_key, $content);
        $send($nodeSuccess)->assertOk();
        $success = $this->event($token, 5, 'execution.succeeded');
        $send($success)->assertOk()->assertJsonPath('data.accepted_sequence', 5);
        self::assertSame('succeeded', $execution->fresh()->status);
        $this->travel(61)->seconds();
        $send($success)->assertOk()->assertJsonPath('data.accepted_sequence', 5);
        $different = $success;
        $different['sequence'] = 6;
        $send($different)->assertStatus(409);
        $send($this->event($token, 6, 'execution.failed', ['error_code' => 'INTERNAL_ERROR']))->assertStatus(409);
        $viewer = User::factory()->create();
        Membership::factory()->create(['user_id' => $viewer->id, 'organization_id' => $execution->organization_id, 'role' => 'viewer']);
        $url = "/api/v1/organizations/$execution->organization_id/executions/$execution->id";
        $this->actingAs($viewer)->getJson($url)->assertOk()->assertJsonPath('data.artifacts.0.asset_id', $asset->id)->assertJsonPath('data.artifacts.0.name', 'result.csv');
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
    }

    public function test_expired_or_wrong_lease_and_invalid_data_cannot_change_state(): void
    {
        [$execution, $token] = $this->running();
        $path = "/internal/v1/executions/$execution->id/events";
        $event = $this->event($token, 1, 'node.started', ['node_id' => 'source']);
        $wrong = $event;
        $wrong['worker_id'] = 'other';
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $wrong)->assertStatus(409);
        $wrong = $event;
        $wrong['sequence'] = '1';
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $wrong)->assertStatus(422);
        $wrong = $event;
        $wrong['extra'] = 'not allowed';
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $wrong)->assertStatus(422);
        $this->travel(61)->seconds();
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $event)->assertStatus(409);
        self::assertTrue((new ExecutionLease)->failOneExpired());
        $this->withToken(self::SERVICE_TOKEN)->postJson($path, $event)->assertStatus(409);
        $this->assertDatabaseCount('execution_events', 0);
    }
}
