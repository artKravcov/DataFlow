<?php

namespace Tests\Feature;

use App\Models\Execution;
use App\Models\FileAsset;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OutboxMessage;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionApiTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id, 'role' => 'owner']);
        $project = $org->projects()->create(['name' => 'Project']);
        $pipeline = Pipeline::factory()->forProject($project)->create();
        $asset = FileAsset::create(['organization_id' => $org->id, 'original_name' => 'input.csv', 'storage_key' => 'assets/test', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'state' => 'ready']);
        $graph = json_decode(file_get_contents(base_path('../contracts/fixtures/csv-filter-map-v1.json')), true, flags: JSON_THROW_ON_ERROR)['graph'];
        $graph['nodes'][0]['config']['asset_id'] = (string) $asset->id;
        $this->actingAs($user)->putJson("/api/v1/organizations/$org->id/pipelines/$pipeline->id/graph", ['revision' => 0, 'graph' => $graph])->assertOk();

        return [$user, $org, $project, $pipeline, $asset, $graph];
    }

    public function test_execution_is_atomic_idempotent_and_snapshot_is_immutable(): void
    {
        [, $org, $project, $pipeline, , $graph] = $this->context();
        $url = "/api/v1/organizations/$org->id/pipelines/$pipeline->id";
        $key = (string) Str::uuid();
        $response = $this->withHeader('Idempotency-Key', $key)->postJson("$url/executions", ['revision' => 1])->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $id = $response->json('data.id');
        $this->assertDatabaseCount('executions', 1);
        $this->assertDatabaseCount('execution_nodes', 4);
        $this->assertDatabaseCount('outbox_messages', 1);
        $payload = OutboxMessage::firstOrFail()->payload;
        $this->assertEqualsCanonicalizing(['message_version', 'message_id', 'execution_id'], array_keys($payload));
        $this->assertSame((string) $id, $payload['execution_id']);
        $this->withHeader('Idempotency-Key', $key)->postJson("$url/executions", ['revision' => 1])->assertStatus(202)->assertJsonPath('data.id', $id);
        $this->withHeader('Idempotency-Key', $key)->postJson("$url/executions", ['revision' => 2])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $otherPipeline = Pipeline::factory()->forProject($project)->create();
        $this->withHeader('Idempotency-Key', $key)->postJson("/api/v1/organizations/$org->id/pipelines/$otherPipeline->id/executions", ['revision' => 1])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("$url/executions", ['revision' => 1])->assertStatus(409)->assertJsonPath('error.code', 'EXECUTION_ACTIVE');
        $this->postJson("$url/archive")->assertStatus(409);
        $this->postJson("/api/v1/organizations/$org->id/projects/$project->id/archive")->assertStatus(409);
        $graph['nodes'][1]['config']['value'] = 'pending';
        $this->putJson("$url/graph", ['revision' => 1, 'graph' => $graph])->assertOk();
        $this->getJson("/api/v1/organizations/$org->id/executions/$id")->assertOk()->assertJsonPath('data.snapshot.graph.nodes.1.config.value', 'paid')->assertJsonPath('data.revision', 1);
        Execution::findOrFail($id)->update(['status' => 'succeeded']);
        $this->withHeader('Idempotency-Key', $key)->postJson("$url/executions", ['revision' => 1])->assertStatus(202)->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'succeeded');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("$url/executions", ['revision' => 1])->assertStatus(409)->assertJsonPath('error.code', 'REVISION_CONFLICT');
        $this->assertDatabaseCount('executions', 1);
    }

    public function test_execution_checks_tenant_roles_key_revision_and_ready_asset(): void
    {
        [$owner, $org, , $pipeline, $asset] = $this->context();
        $url = "/api/v1/organizations/$org->id/pipelines/$pipeline->id/executions";
        $this->postJson($url, ['revision' => 1])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'bad')->postJson($url, ['revision' => 1])->assertUnprocessable();
        $asset->update(['state' => 'processing']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['revision' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('executions', 0);
        $asset->update(['state' => 'ready']);
        $viewer = User::factory()->create();
        Membership::factory()->create(['user_id' => $viewer->id, 'organization_id' => $org->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['revision' => 1])->assertForbidden();
        $other = Organization::factory()->create();
        Membership::factory()->create(['user_id' => $owner->id, 'organization_id' => $other->id, 'role' => 'owner']);
        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/organizations/$other->id/pipelines/$pipeline->id/executions", ['revision' => 1])->assertNotFound();
        $id = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['revision' => 1])->assertStatus(202)->json('data.id');
        $this->getJson("/api/v1/organizations/$other->id/executions/$id")->assertNotFound();
        $this->actingAs($viewer)->getJson("/api/v1/organizations/$org->id/executions/$id")->assertOk();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/organizations/$org->id/executions/$id")->assertNotFound();
    }

    public function test_database_rejects_two_active_executions_even_if_application_check_is_bypassed(): void
    {
        [, $org, , $pipeline] = $this->context();
        $url = "/api/v1/organizations/$org->id/pipelines/$pipeline->id/executions";
        $id = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['revision' => 1])->assertStatus(202)->json('data.id');
        $first = Execution::findOrFail($id);
        try {
            DB::transaction(function () use ($first) {
                Execution::create(['organization_id' => $first->organization_id, 'pipeline_id' => $first->pipeline_id, 'requested_by' => $first->requested_by, 'idempotency_key' => (string) Str::uuid(), 'revision' => $first->revision, 'status' => 'running', 'snapshot' => $first->snapshot]);
            });
            self::fail('Expected unique index to reject another active execution');
        } catch (QueryException $e) {
            self::assertSame('23505', $e->errorInfo[0]);
        }
        $this->assertDatabaseCount('executions', 1);
    }

    public function test_pipeline_execution_list_is_bounded_and_isolated_by_organization(): void
    {
        [$owner, $org, $project, $pipeline] = $this->context();
        $url = "/api/v1/organizations/$org->id/pipelines/$pipeline->id/executions";
        $id = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['revision' => 1])->assertStatus(202)->json('data.id');
        $first = Execution::findOrFail($id);
        $first->update(['status' => 'succeeded']);
        for ($i = 0; $i < 11; $i++) {
            Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => $owner->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 1, 'status' => 'succeeded', 'snapshot' => $first->snapshot]);
        }
        $otherPipeline = Pipeline::factory()->forProject($project)->create();
        Execution::create(['organization_id' => $org->id, 'pipeline_id' => $otherPipeline->id, 'requested_by' => $owner->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 1, 'status' => 'succeeded', 'snapshot' => $first->snapshot]);
        $response = $this->getJson($url)->assertOk()->assertJsonCount(10, 'data')->assertJsonMissing(['id' => $first->id]);
        self::assertGreaterThan($response->json('data.1.id'), $response->json('data.0.id'));
        $viewer = User::factory()->create();
        Membership::factory()->create(['user_id' => $viewer->id, 'organization_id' => $org->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->getJson($url)->assertOk()->assertJsonCount(10, 'data');
        $other = Organization::factory()->create();
        Membership::factory()->create(['user_id' => $owner->id, 'organization_id' => $other->id, 'role' => 'owner']);
        $this->actingAs($owner)->getJson("/api/v1/organizations/$other->id/pipelines/$pipeline->id/executions")->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
    }

    public function test_organization_execution_history_filters_and_hides_foreign_identifiers(): void
    {
        [$owner, $org, $project, $pipeline] = $this->context();
        $first = Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => $owner->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 1, 'status' => 'succeeded', 'snapshot' => ['graph' => []]]);
        $failed = Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => $owner->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 1, 'status' => 'failed', 'error_code' => 'INVALID_CSV', 'snapshot' => ['graph' => []]]);
        $response = $this->getJson("/api/v1/organizations/$org->id/executions?status=failed&project_id=$project->id")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $failed->id)->assertJsonPath('data.0.pipeline_name', $pipeline->name)->assertJsonPath('meta.total', 1);
        $this->assertNotEquals($first->id, $response->json('data.0.id'));
        $viewer = User::factory()->create();
        Membership::factory()->create(['user_id' => $viewer->id, 'organization_id' => $org->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->getJson("/api/v1/organizations/$org->id/executions")->assertOk()->assertJsonCount(2, 'data');
        $foreign = Organization::factory()->create();
        $foreignProject = $foreign->projects()->create(['name' => 'Foreign']);
        $foreignPipeline = Pipeline::factory()->forProject($foreignProject)->create();
        Membership::factory()->create(['user_id' => $owner->id, 'organization_id' => $foreign->id, 'role' => 'owner']);
        $this->actingAs($owner)->getJson("/api/v1/organizations/$org->id/executions?pipeline_id=$foreignPipeline->id")->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/organizations/$org->id/executions")->assertNotFound();
    }

    public function test_failure_to_create_outbox_rolls_back_execution_and_nodes(): void
    {
        [, $org, , $pipeline] = $this->context();
        OutboxMessage::creating(fn () => throw new \RuntimeException('simulated outbox failure'));
        try {
            $this->withoutExceptionHandling()->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/organizations/$org->id/pipelines/$pipeline->id/executions", ['revision' => 1]);
            self::fail('Expected failure');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated outbox failure', $e->getMessage());
        } finally {
            OutboxMessage::flushEventListeners();
        }
        $this->assertDatabaseCount('executions', 0);
        $this->assertDatabaseCount('execution_nodes', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }
}
