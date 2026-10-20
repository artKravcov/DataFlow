<?php

namespace Tests\Unit;

use App\Execution\StreamPublisher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StreamPublisherTest extends TestCase
{
    #[DataProvider('badEnvelopes')]
    public function test_rejects_untrusted_or_incompatible_envelopes_before_contacting_redis(array $envelope): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StreamPublisher)->publish($envelope);
    }

    public static function badEnvelopes(): array
    {
        $valid = ['message_version' => 1, 'message_id' => '7e61bc49-e740-4fe2-9db8-f9cafc2b53c4', 'execution_id' => '123'];
        return [
            'extra field' => [array_merge($valid, ['credentials' => 'not allowed'])],
            'wrong version' => [array_merge($valid, ['message_version' => 2])],
            'numeric id' => [array_merge($valid, ['execution_id' => 123])],
            'zero id' => [array_merge($valid, ['execution_id' => '0'])],
            'invalid uuid' => [array_merge($valid, ['message_id' => 'not-a-uuid'])],
            'missing key' => [['message_version' => 1]],
        ];
    }
}
