<?php

namespace Tests\Feature;

use App\Execution\OutboxDispatcher;
use App\Execution\StreamPublisher;
use App\Models\Execution;
use App\Models\Organization;
use App\Models\OutboxMessage;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function queuedMessage(): OutboxMessage
    {
        $org = Organization::factory()->create();
        $project = $org->projects()->create(['name' => 'Project']);
        $pipeline = Pipeline::factory()->forProject($project)->create();
        $user = User::factory()->create();
        $execution = Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => $user->id, 'idempotency_key' => (string) Str::uuid(), 'revision' => 0, 'status' => 'queued', 'snapshot' => ['graph' => []]]);
        $id = (string) Str::uuid();

        return OutboxMessage::create(['id' => $id, 'execution_id' => $execution->id, 'message_type' => 'execution.requested', 'payload' => ['message_version' => 1, 'message_id' => $id, 'execution_id' => (string) $execution->id]]);
    }

    public function test_success_is_marked_once_and_failed_publish_is_delayed(): void
    {
        $message = $this->queuedMessage();
        $publisher = new class extends StreamPublisher
        {
            public bool $fail = true;

            public array $sent = [];

            public function publish(array $envelope, string $stream = self::STREAM): string
            {
                if ($this->fail) {
                    throw new \RuntimeException('transport failure');
                }
                $this->sent[] = $envelope;

                return '1-0';
            }
        };
        $dispatcher = new OutboxDispatcher($publisher);
        self::assertSame('retry', $dispatcher->dispatchOne());
        self::assertSame(1, $message->fresh()->attempts);
        self::assertNull($message->fresh()->published_at);
        self::assertSame('idle', $dispatcher->dispatchOne());
        $message->update(['next_attempt_at' => now()->subSecond()]);
        $publisher->fail = false;
        self::assertSame('published', $dispatcher->dispatchOne());
        self::assertNotNull($message->fresh()->published_at);
        self::assertSame(2, $message->fresh()->attempts);
        self::assertSame($message->id, $publisher->sent[0]['message_id']);
        self::assertSame('idle', $dispatcher->dispatchOne());
    }

    public function test_mismatched_outbox_data_is_not_published(): void
    {
        $message = $this->queuedMessage();
        $payload = $message->payload;
        $payload['execution_id'] = '99999';
        $message->update(['payload' => $payload]);
        $publisher = new class extends StreamPublisher
        {
            public bool $called = false;

            public function publish(array $envelope, string $stream = self::STREAM): string
            {
                $this->called = true;

                return '1-0';
            }
        };
        self::assertSame('retry', (new OutboxDispatcher($publisher))->dispatchOne());
        self::assertFalse($publisher->called);
        self::assertNull($message->fresh()->published_at);
    }

    public function test_lost_database_confirmation_may_publish_the_same_message_again(): void
    {
        $message = $this->queuedMessage();
        $publisher = new class extends StreamPublisher
        {
            public array $sent = [];

            public function publish(array $envelope, string $stream = self::STREAM): string
            {
                $this->sent[] = $envelope;

                return '1-0';
            }
        };
        $dispatcher = new OutboxDispatcher($publisher);
        OutboxMessage::updating(fn () => throw new \RuntimeException('simulated database failure'));
        try {
            $dispatcher->dispatchOne();
            self::fail('Expected database failure');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated database failure', $e->getMessage());
        } finally {
            OutboxMessage::flushEventListeners();
        }
        self::assertNull($message->fresh()->published_at);
        self::assertSame('published', $dispatcher->dispatchOne());
        self::assertCount(2, $publisher->sent);
        self::assertSame($publisher->sent[0], $publisher->sent[1]);
    }

    public function test_real_redis_repeated_publication_after_lost_confirmation(): void
    {
        if (getenv('DATAFLOW_REDIS_INTEGRATION') !== '1') {
            $this->markTestSkipped('Run explicitly with DATAFLOW_REDIS_INTEGRATION=1.');
        }
        $message = $this->queuedMessage();
        $stream = 'dataflow:contract-test:'.Str::uuid();
        $publisher = new class($stream) extends StreamPublisher
        {
            public bool $first = true;

            public function __construct(private string $testStream) {}

            public function publish(array $envelope, string $stream = self::STREAM): string
            {
                $result = parent::publish($envelope, $this->testStream);
                Redis::connection('streams')->client()->rawCommand('EXPIRE', $this->testStream, '120');
                if ($this->first) {
                    $this->first = false;
                    throw new \RuntimeException('simulated lost confirmation');
                }

                return $result;
            }
        };
        $dispatcher = new OutboxDispatcher($publisher);
        self::assertSame('retry', $dispatcher->dispatchOne());
        self::assertNull($message->fresh()->published_at);
        $message->update(['next_attempt_at' => now()->subSecond()]);
        self::assertSame('published', $dispatcher->dispatchOne());
        $raw = Redis::connection('streams')->client()->rawCommand('XRANGE', $stream, '-', '+');
        self::assertCount(2, $raw);
        self::assertSame($raw[0][1][1], $raw[1][1][1]);
        self::assertSame($message->id, json_decode($raw[0][1][1], true, flags: JSON_THROW_ON_ERROR)['message_id']);
    }
}
