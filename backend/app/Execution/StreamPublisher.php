<?php

namespace App\Execution;

use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

class StreamPublisher
{
    public const STREAM = 'dataflow:executions:v1';

    public function publish(array $envelope, string $stream = self::STREAM): string
    {
        $keys = array_keys($envelope);
        sort($keys);
        if ($keys !== ['execution_id', 'message_id', 'message_version']
            || $envelope['message_version'] !== 1
            || ! is_string($envelope['message_id'])
            || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $envelope['message_id'])
            || ! is_string($envelope['execution_id'])
            || ! preg_match('/^[1-9][0-9]{0,17}$/D', $envelope['execution_id'])) {
            throw new InvalidArgumentException('Invalid execution envelope.');
        }
        $json = json_encode($envelope, JSON_THROW_ON_ERROR);
        // rawCommand intentionally avoids Laravel's connection key prefix.
        try {
            $id = Redis::connection('streams')->client()->rawCommand('XADD', $stream, '*', 'envelope', $json);
            if (! is_string($id)) {
                throw new \RuntimeException('Redis did not confirm publication.');
            }
        } catch (\Throwable $exception) {
            // A long-lived dispatcher must create a fresh connection after Redis restarts.
            Redis::purge('streams');
            throw $exception;
        }
        return $id;
    }
}
