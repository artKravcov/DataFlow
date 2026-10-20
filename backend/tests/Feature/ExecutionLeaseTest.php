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

class ExecutionLeaseTest extends TestCase
{
    use RefreshDatabase;

    private function queued(): Execution
    {
        $org = Organization::factory()->create();
        $project = $org->projects()->create(['name' => 'Project']);
        $pipeline = Pipeline::factory()->forProject($project)->create();

        return Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => User::factory()->create()->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 0, 'status' => 'queued', 'snapshot' => ['graph' => []]]);
    }

    public function test_claim_is_exclusive_and_heartbeat_requires_the_current_token_and_worker(): void
    {
        $execution = $this->queued();
        $leases = new ExecutionLease;
        $token = $leases->claim($execution->id, 'worker-a');
        self::assertNotNull($token);
        self::assertNotSame($token, $execution->fresh()->lease_token_hash);
        self::assertNull($leases->claim($execution->id, 'worker-a'));
        self::assertNull($leases->claim($execution->id, 'worker-b'));
        self::assertFalse($leases->heartbeat($execution->id, 'worker-b', $token));
        self::assertFalse($leases->heartbeat($execution->id, 'worker-a', 'wrong'));
        $this->travel(20)->seconds();
        self::assertTrue($leases->heartbeat($execution->id, 'worker-a', $token));
        self::assertTrue($execution->fresh()->lease_expires_at->greaterThan(now()->addSeconds(59)));
    }

    public function test_expired_lease_cannot_be_revived_and_watchdog_does_not_restart_work(): void
    {
        $execution = $this->queued();
        $running = ExecutionNode::create(['execution_id' => $execution->id, 'snapshot_node_id' => 'one', 'status' => 'running']);
        $pending = ExecutionNode::create(['execution_id' => $execution->id, 'snapshot_node_id' => 'two', 'status' => 'pending']);
        $leases = new ExecutionLease;
        $token = $leases->claim($execution->id, 'worker-a');
        $this->travel(61)->seconds();
        self::assertFalse($leases->heartbeat($execution->id, 'worker-a', $token));
        self::assertTrue($leases->failOneExpired());
        self::assertSame('failed', $execution->fresh()->status);
        self::assertSame('WORKER_LOST', $execution->fresh()->error_code);
        self::assertNull($execution->fresh()->lease_token_hash);
        self::assertSame('failed', $running->fresh()->status);
        self::assertSame('skipped', $pending->fresh()->status);
        self::assertNull($leases->claim($execution->id, 'worker-b'));
        self::assertFalse($leases->heartbeat($execution->id, 'worker-a', $token));
        self::assertFalse($leases->failOneExpired());
    }
}
