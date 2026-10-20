<?php

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
    Redis::connection()->client()->rawCommand('EXPIRE', $stream, '120');
    $publisher->publish($fixture, $stream);
    $this->info('Published two identical test envelopes.');
})->purpose('Publish a temporary cross-runtime contract fixture');
