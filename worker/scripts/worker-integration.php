<?php

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'testing' || config('database.connections.pgsql.database') !== 'dataflow_test' || DB::selectOne('select current_database() as name')->name !== 'dataflow_test') {
    throw new RuntimeException('Refusing to use a non-test database.');
}
if (in_array(($argv[1] ?? ''), ['prepare', 'prepare-runtime'], true)) {
    $runtime = $argv[1] === 'prepare-runtime';
    if ($runtime && getenv('DATAFLOW_RUNTIME_TEST') !== '1') {
        throw new RuntimeException('Runtime fixtures require the isolated runtime test environment.');
    }
    Artisan::call('migrate', ['--force' => true]);
    $org = App\Models\Organization::factory()->create();
    $project = $org->projects()->create(['name' => 'Integration']);
    $pipeline = App\Models\Pipeline::factory()->forProject($project)->create();
    $content = "order_id,status,total\n101,paid,12.50\n102,pending,8.00\n103,paid,20.00\n";
    $key = 'assets/integration-'.Illuminate\Support\Str::uuid();
    Storage::disk('local')->put($key, $content);
    $asset = App\Models\FileAsset::create(['organization_id' => $org->id, 'original_name' => 'input.csv', 'storage_key' => $key, 'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content), 'state' => 'ready']);
    $graph = json_decode(file_get_contents('/var/www/contracts/fixtures/csv-filter-map-v1.json'), true, flags: JSON_THROW_ON_ERROR)['graph'];
    $graph['nodes'][0]['config']['asset_id'] = (string) $asset->id;
    $execution = App\Models\Execution::create(['organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'requested_by' => App\Models\User::factory()->create()->id, 'idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'revision' => 1, 'status' => 'queued', 'snapshot' => ['revision' => 1, 'graph' => $graph]]);
    foreach ($graph['nodes'] as $node) {
        App\Models\ExecutionNode::create(['execution_id' => $execution->id, 'snapshot_node_id' => $node['id'], 'status' => 'pending']);
    }
    $stream = 'dataflow:contract-test:'.Illuminate\Support\Str::uuid();
    $envelope = ['message_version' => 1, 'message_id' => (string) Illuminate\Support\Str::uuid(), 'execution_id' => (string) $execution->id];
    if ($runtime) {
        App\Models\OutboxMessage::create(['id' => $envelope['message_id'], 'execution_id' => $execution->id, 'message_type' => 'execution.requested', 'payload' => $envelope]);
        echo json_encode(['execution_id' => (string) $execution->id], JSON_THROW_ON_ERROR);
        exit;
    }
    $publisher = app(App\Execution\StreamPublisher::class);
    $publisher->publish($envelope, $stream);
    Illuminate\Support\Facades\Redis::connection('streams')->client()->rawCommand('EXPIRE', $stream, '120');
    $publisher->publish($envelope, $stream);
    echo json_encode(['execution_id' => (string) $execution->id, 'stream' => $stream], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'status' && getenv('DATAFLOW_RUNTIME_TEST') === '1' && preg_match('/^[1-9][0-9]{0,17}$/D', $argv[2] ?? '')) {
    $execution = App\Models\Execution::findOrFail($argv[2]);
    $message = App\Models\OutboxMessage::where('execution_id', $execution->id)->firstOrFail();
    echo json_encode(['status' => $execution->status, 'published' => $message->published_at !== null], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'verify' && preg_match('/^[1-9][0-9]{0,17}$/D', $argv[2] ?? '')) {
    $execution = App\Models\Execution::findOrFail($argv[2]);
    $artifact = DB::table('execution_artifacts')->where('execution_id', $execution->id)->first();
    $asset = $artifact ? App\Models\FileAsset::find($artifact->file_asset_id) : null;
    if ($execution->status !== 'succeeded' || ! $asset || $asset->organization_id !== $execution->organization_id || Storage::disk('local')->get($asset->storage_key) !== "id,amount\n101,12.50\n103,20.00\n" || DB::table('execution_events')->where('execution_id', $execution->id)->count() !== 9) {
        throw new RuntimeException('Integration result differs from expected CSV and execution state.');
    }
    $source = App\Models\FileAsset::findOrFail($execution->snapshot['graph']['nodes'][0]['config']['asset_id']);
    if (! str_starts_with($source->storage_key, 'assets/integration-')) throw new RuntimeException('Invalid test file.');
    Storage::disk('local')->delete([$source->storage_key, $asset->storage_key]);
    echo "Verified isolated execution, nine events and output CSV.\n";
} elseif (($argv[1] ?? '') === 'cleanup' && preg_match('/^[1-9][0-9]{0,17}$/D', $argv[2] ?? '')) {
    $execution = App\Models\Execution::find($argv[2]);
    if ($execution) {
        $source = App\Models\FileAsset::find($execution->snapshot['graph']['nodes'][0]['config']['asset_id'] ?? null);
        if (! $source || ! str_starts_with($source->storage_key, 'assets/integration-')) throw new RuntimeException('Invalid test file.');
        $artifact = DB::table('execution_artifacts')->where('execution_id', $execution->id)->first();
        $output = $artifact ? App\Models\FileAsset::where('organization_id', $execution->organization_id)->find($artifact->file_asset_id) : null;
        Storage::disk('local')->delete($source->storage_key);
        if ($output) Storage::disk('local')->delete($output->storage_key);
    }
} else {
    throw new RuntimeException('Expected prepare, verify or cleanup with test execution ID.');
}
