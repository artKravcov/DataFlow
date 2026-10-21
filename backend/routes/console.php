<?php

use App\Execution\ExecutionLease;
use App\Execution\OutboxDispatcher;
use App\Execution\QueueReconciler;
use App\Execution\StreamPublisher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// This command operates only on short-lived, explicitly named test streams.
Artisan::command('dataflow:publish-contract-fixture {stream}', function (StreamPublisher $publisher) {
    $stream = $this->argument('stream');
    if (! preg_match('/^dataflow:contract-test:[0-9a-f-]{36}$/D', $stream)) {
        throw new InvalidArgumentException('A dedicated test stream is required.');
    }
    $fixture = json_decode(file_get_contents(base_path('../contracts/fixtures/redis-envelope-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $publisher->publish($fixture, $stream);
    // Set TTL immediately so even an interrupted contract check is cleaned up.
    Redis::connection('streams')->client()->rawCommand('EXPIRE', $stream, '120');
    $publisher->publish($fixture, $stream);
    $this->info('Published two identical test envelopes.');
})->purpose('Publish a temporary cross-runtime contract fixture');

// Bounded operations are also used by the opt-in runtime loop below.
Artisan::command('dataflow:dispatch-outbox {--limit=25}', function (OutboxDispatcher $dispatcher) {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($limit === false) {
        throw new InvalidArgumentException('Limit must be between 1 and 100.');
    }
    $counts = ['published' => 0, 'retry' => 0];
    for ($i = 0; $i < $limit; $i++) {
        $result = $dispatcher->dispatchOne();
        if ($result === 'idle') {
            break;
        }
        $counts[$result]++;
    }
    $this->info("Published: {$counts['published']}; retry pending: {$counts['retry']}.");
})->purpose('Publish a bounded batch of outbox messages');

Artisan::command('dataflow:recover-executions {--limit=25}', function (QueueReconciler $reconciler, ExecutionLease $leases) {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($limit === false) {
        throw new InvalidArgumentException('Limit must be between 1 and 100.');
    }
    $lost = 0;
    for ($i = 0; $i < $limit && $leases->failOneExpired(); $i++) {
        $lost++;
    }
    $counts = ['waiting' => 0, 'republish' => 0, 'timed_out' => 0];
    for ($i = 0; $i < $limit; $i++) {
        $result = $reconciler->reconcileOne();
        if ($result === 'idle') {
            break;
        }
        $counts[$result]++;
    }
    $this->info("Worker lost: $lost; waiting: {$counts['waiting']}; republish: {$counts['republish']}; queue timeout: {$counts['timed_out']}.");
})->purpose('Recover a bounded batch of stalled executions');

// Each iteration is bounded; failure of either task does not prevent the other.
Artisan::command('dataflow:queue-loop {--once}', function () {
    if (! $this->option('once') && env('DATAFLOW_WORKER_ENABLED', '0') !== '1') {
        $this->error('Queue manager requires DATAFLOW_WORKER_ENABLED=1.');

        return 1;
    }
    do {
        $failed = false;
        foreach (['dataflow:recover-executions', 'dataflow:dispatch-outbox'] as $command) {
            try {
                if (Artisan::call($command, ['--limit' => 25]) !== 0) {
                    throw new RuntimeException('Queue task failed.');
                }
            } catch (Throwable $exception) {
                // Do not expose database addresses, payloads, or credentials in logs.
                $this->error("Queue task $command failed; it will be retried.");
                $failed = true;
            }
        }
        if ($this->option('once')) {
            return $failed ? 1 : 0;
        }
        sleep(5);
    } while (true);
})->purpose('Run bounded queue recovery and dispatch continuously');
