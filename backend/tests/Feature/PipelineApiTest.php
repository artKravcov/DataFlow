<?php

namespace Tests\Feature;

use App\Graph\AssetResolver;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineApiTest extends TestCase
{
    use RefreshDatabase;

    private function setupContext(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id, 'role' => $role]);
        $project = $org->projects()->create(['name' => 'Project']);
        $pipeline = Pipeline::factory()->forProject($project)->create();
        $this->actingAs($user);

        return [$user, $org, $project, $pipeline];
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('../contracts/fixtures/csv-filter-map-v1.json')), true, flags: JSON_THROW_ON_ERROR)['graph'];
    }

    public function test_save_read_validate_and_revision_conflict(): void
    {
        [, $org, , $pipeline] = $this->setupContext();
        $this->app->instance(AssetResolver::class, new class($org->id) implements AssetResolver
        {
            public function __construct(private int $org) {}

            public function isReadyForOrganization(string $assetId, int $organizationId): bool
            {
                return $organizationId === $this->org && $assetId === 'asset-demo';
            }
        });
        $url = "/api/v1/organizations/$org->id/pipelines/$pipeline->id";
        $graph = $this->fixture();
        $this->putJson("$url/graph", ['revision' => 0, 'graph' => $graph])->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.graph.nodes.1.config.field', 'status');
        $this->getJson($url)->assertOk()->assertJsonPath('data.graph.nodes.0.position.x', 0)->assertJsonPath('data.graph.nodes.2.config.mappings.0.target', 'id');
        $this->postJson("$url/validate", ['revision' => 1])->assertOk()->assertJsonPath('data.valid', true);
        $this->putJson("$url/graph", ['revision' => 0, 'graph' => ['schema_version' => 1, 'nodes' => [], 'edges' => []]])->assertStatus(409)->assertJsonPath('error.code', 'REVISION_CONFLICT');
        $this->assertDatabaseCount('pipeline_nodes', 4);
    }

    public function test_invalid_graph_does_not_replace_existing_graph(): void
    {
        [, $org, , $pipeline] = $this->setupContext();
        $url = "/api/v1/organizations/$org->id/pipelines/$pipeline->id/graph";
        $graph = $this->fixture();
        unset($graph['nodes'][0]['config']['asset_id']);
        $this->putJson($url, ['revision' => 0, 'graph' => $graph])->assertOk();
        $graph['edges'][] = ['id' => 'cycle', 'source' => 'map', 'source_port' => 'records', 'target' => 'filter', 'target_port' => 'records'];
        $this->putJson($url, ['revision' => 1, 'graph' => $graph])->assertStatus(422);
        $this->assertDatabaseCount('pipeline_edges', 3);
        $this->assertSame(1, $pipeline->fresh()->revision);
        $graph['edges'] = [];
        $graph['nodes'][0]['config']['asset_id'] = 'arbitrary';
        $this->putJson($url, ['revision' => 1, 'graph' => $graph])->assertStatus(422);
        $graph['nodes'][0]['config'] = ['password' => 'secret'];
        $this->putJson($url, ['revision' => 1, 'graph' => $graph])->assertStatus(422);
    }

    public function test_transaction_rolls_back_if_insertion_fails(): void
    {
        [, $org, , $pipeline] = $this->setupContext();
        $url = "/api/v1/organizations/$org->id/pipelines/$pipeline->id/graph";
        $graph = $this->fixture();
        unset($graph['nodes'][0]['config']['asset_id']);
        $this->putJson($url, ['revision' => 0, 'graph' => $graph])->assertOk();
        PipelineNode::creating(function () {
            throw new \RuntimeException('simulated failure');
        });
        try {
            $this->withoutExceptionHandling()->putJson($url, ['revision' => 1, 'graph' => $graph]);
            self::fail('Expected insertion failure');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated failure', $e->getMessage());
        } finally {
            PipelineNode::flushEventListeners();
        }
        $this->assertDatabaseCount('pipeline_nodes', 4);
        $this->assertDatabaseCount('pipeline_edges', 3);
        self::assertSame(1, $pipeline->fresh()->revision);
    }

    public function test_tenant_and_role_checks_apply_to_nested_resources(): void
    {
        [, $org, $project, $pipeline] = $this->setupContext('viewer');
        $other = Organization::factory()->create();
        $otherProject = $other->projects()->create(['name' => 'Foreign']);
        $otherPipeline = Pipeline::factory()->forProject($otherProject)->create();
        $this->getJson("/api/v1/organizations/$org->id/pipelines/$otherPipeline->id")->assertNotFound();
        $this->postJson("/api/v1/organizations/$org->id/projects/$otherProject->id/pipelines", ['name' => 'No'])->assertNotFound();
        $this->getJson("/api/v1/organizations/$org->id/pipelines/$pipeline->id")->assertOk();
        $this->postJson("/api/v1/organizations/$org->id/projects/$project->id/pipelines", ['name' => 'No'])->assertForbidden();
        $this->putJson("/api/v1/organizations/$org->id/pipelines/$pipeline->id/graph", ['revision' => 0, 'graph' => ['schema_version' => 1, 'nodes' => [], 'edges' => []]])->assertForbidden();
        $this->postJson("/api/v1/organizations/$org->id/pipelines/$pipeline->id/archive")->assertForbidden();
    }

    public function test_draft_contract_checks_ports_types_and_known_schema(): void
    {
        [, $org, , $pipeline] = $this->setupContext();
        $url = "/api/v1/organizations/$org->id/pipelines/$pipeline->id/graph";
        $graph = $this->fixture();
        unset($graph['nodes'][0]['config']['asset_id']);
        $invalids = [];
        $invalid = $graph;
        $invalid['nodes'][1]['type'] = 'other';
        $invalids[] = $invalid;
        $invalid = $graph;
        $invalid['nodes'][1]['type_version'] = 2;
        $invalids[] = $invalid;
        $invalid = $graph;
        $invalid['edges'][0]['source_port'] = 'missing';
        $invalids[] = $invalid;
        $invalid = $graph;
        $invalid['edges'][] = array_merge($invalid['edges'][0], ['id' => 'duplicate']);
        $invalids[] = $invalid;
        $invalid = $graph;
        $invalid['nodes'][1]['config']['field'] = 'missing';
        $invalids[] = $invalid;
        $invalid = $graph;
        $invalid['nodes'][2]['config']['mappings'][0]['source'] = 'missing';
        $invalids[] = $invalid;
        $invalid = $graph;
        $invalid['nodes'][2]['config']['mappings'][1]['target'] = 'id';
        $invalids[] = $invalid;
        foreach ($invalids as $invalid) {
            $this->putJson($url, ['revision' => 0, 'graph' => $invalid])->assertStatus(422);
        }
        $this->assertDatabaseCount('pipeline_nodes', 0);
        $this->assertSame(0, $pipeline->fresh()->revision);
    }

    public function test_create_archive_and_incomplete_draft(): void
    {
        [, $org, $project] = $this->setupContext();
        $response = $this->postJson("/api/v1/organizations/$org->id/projects/$project->id/pipelines", ['name' => 'New'])->assertCreated()->assertJsonPath('data.revision', 0);
        $id = $response->json('data.id');
        $url = "/api/v1/organizations/$org->id/pipelines/$id";
        $this->getJson('/api/v1/node-types')->assertOk()->assertJsonPath('data.0.runtime_available', false);
        $this->putJson("$url/graph", ['revision' => 0, 'graph' => ['schema_version' => 1, 'nodes' => [], 'edges' => []]])->assertOk();
        $this->postJson("$url/validate", ['revision' => 1])->assertStatus(422);
        $this->postJson("$url/archive")->assertOk();
        $this->getJson($url)->assertNotFound();
    }
}
