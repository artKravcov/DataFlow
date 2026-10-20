<?php

namespace Tests\Feature;

use App\Execution\ExecutionLease;
use App\Execution\OutboxDispatcher;
use App\Execution\QueueReconciler;
use App\Execution\StreamPublisher;
use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\Organization;
use App\Models\OutboxMessage;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function queued(int $ageSeconds = 31): array
    {
        $org = Organization::factory()->create();
        $project = $org->projects()->create(['name' => 'Project']);
        $pipeline = Pipeline::factory()->forProject($project)->create();
        $execution = Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => User::factory()->create()->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 0, 'status' => 'queued', 'snapshot' => ['graph' => []], 'created_at' => now()->subSeconds($ageSeconds)]);
        ExecutionNode::create(['execution_id' => $execution->id, 'snapshot_node_id' => 'source', 'status' => 'pending']);
        $id = (string) Str::uuid();
        $message = OutboxMessage::create(['id' => $id, 'execution_id' => $execution->id, 'message_type' => 'execution.requested', 'payload' => ['message_version' => 1, 'message_id' => $id, 'execution_id' => (string) $execution->id]]);

        return [$execution, $message];
    }

    public function test_queued_recovery_reuses_published_message_and_respects_backoff(): void
    {
        [$execution, $message] = $this->queued();
        $message->update(['published_at' => now()->subSeconds(30)]);
        $reconciler = new QueueReconciler;
        self::assertSame('republish', $reconciler->reconcileOne());
        self::assertNull($message->fresh()->published_at);
        self::assertSame($message->id, $message->fresh()->payload['message_id']);
        self::assertSame('idle', $reconciler->reconcileOne());
        $this->travel(3)->seconds();
        self::assertSame('waiting', $reconciler->reconcileOne());
        self::assertSame(2, $execution->fresh()->queue_checks);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_missing_outbox_is_restored_and_recent_or_claimed_runs_are_not_requeued(): void
    {
        [$recent] = $this->queued(10);
        [$running] = $this->queued();
        [$lost, $message] = $this->queued();
        $message->delete();
        $lease = new ExecutionLease;
        self::assertNotNull($lease->claim($running->id, 'worker'));
        $reconciler = new QueueReconciler;
        self::assertSame('republish', $reconciler->reconcileOne());
        $restored = OutboxMessage::where('execution_id', $lost->id)->firstOrFail();
        self::assertSame((string) $lost->id, $restored->payload['execution_id']);
        self::assertSame('idle', $reconciler->reconcileOne());
        self::assertSame('queued', $recent->fresh()->status);
        self::assertSame('running', $running->fresh()->status);
    }

    public function test_queue_timeout_and_worker_loss_do_not_restart_work(): void
    {
        [$queued] = $this->queued(601);
        $queued->update(['queue_next_check_at' => now()->addMinute()]);
        [$running] = $this->queued();
        $lease = new ExecutionLease;
        $token = $lease->claim($running->id, 'worker');
        ExecutionNode::where('execution_id', $running->id)->update(['status' => 'running']);
        self::assertSame('timed_out', (new QueueReconciler)->reconcileOne());
        self::assertSame('QUEUE_TIMEOUT', $queued->fresh()->error_code);
        $this->assertDatabaseHas('execution_nodes', ['execution_id' => $queued->id, 'status' => 'skipped']);
        $this->travel(61)->seconds();
        self::assertTrue($lease->failOneExpired());
        self::assertSame('WORKER_LOST', $running->fresh()->error_code);
        $this->assertDatabaseHas('execution_nodes', ['execution_id' => $running->id, 'status' => 'failed']);
        self::assertFalse($lease->heartbeat($running->id, 'worker', $token));
        self::assertNull($lease->claim($running->id, 'worker-2'));
        self::assertSame('idle', (new QueueReconciler)->reconcileOne());
    }

    public function test_real_redis_loss_repairs_published_queue_work(): void
    {
        if (getenv('DATAFLOW_REDIS_INTEGRATION') !== '1') {
            $this->markTestSkipped('Run explicitly with DATAFLOW_REDIS_INTEGRATION=1.');
        }
        [$execution, $message] = $this->queued();
        $stream = 'dataflow:contract-test:'.Str::uuid();
        $publisher = new class($stream) extends StreamPublisher
        {
            public function __construct(private string $testStream) {}

            public function publish(array $envelope, string $stream = self::STREAM): string
            {
                $id = parent::publish($envelope, $this->testStream);
                Redis::connection('streams')->client()->rawCommand('EXPIRE', $this->testStream, '120');

                return $id;
            }
        };
        $dispatcher = new OutboxDispatcher($publisher);
        self::assertSame('published', $dispatcher->dispatchOne());
        Redis::connection('streams')->client()->rawCommand('DEL', $stream);
        self::assertSame('republish', (new QueueReconciler)->reconcileOne());
        self::assertSame('published', $dispatcher->dispatchOne());
        $entries = Redis::connection('streams')->client()->rawCommand('XRANGE', $stream, '-', '+');
        self::assertCount(1, $entries);
        $published = json_decode($entries[0][1][1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($message->id, $published['message_id']);
        self::assertSame((string) $execution->id, $published['execution_id']);
    }
}
