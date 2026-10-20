<?php

namespace Tests\Feature;

use App\Execution\ExecutionLease;
use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $graph = ['nodes' => [['id' => 'source', 'type' => 'csv.source'], ['id' => 'destination', 'type' => 'csv.destination']], 'edges' => [['source' => 'source', 'target' => 'destination']]];
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
