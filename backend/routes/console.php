<?php

use App\Execution\OutboxDispatcher;
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

// Deliberately not scheduled until worker claim and Redis recovery are implemented.
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
