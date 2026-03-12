<?php

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Mockery;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Services\SqsClientService;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;

class SqsBufferTest extends TestCase
{
    public function test_it_adds_requests_and_exceptions_to_buffer()
    {
        $sqsClientMock = Mockery::mock(SqsClientService::class);
        $buffer = new SqsBuffer($sqsClientMock);

        $buffer->addRequest(['url' => 'http://localhost/test']);
        $buffer->addException(['message' => 'Test Error']);

        // Use reflection to inspect protected property
        $reflection = new \ReflectionClass($buffer);
        $property = $reflection->getProperty('messages');
        $messages = $property->getValue($buffer);

        $this->assertCount(2, $messages);
        $this->assertEquals('request', $messages[0]['type']);
        $this->assertEquals('http://localhost/test', $messages[0]['url']);
        
        $this->assertEquals('exception', $messages[1]['type']);
        $this->assertEquals('Test Error', $messages[1]['message']);
    }

    public function test_it_flushes_buffer_to_client_in_batches()
    {
        // Force batch size to 2 for this test
        config(['sqs-telemetry.batch_size' => 2]);

        $sqsClientMock = Mockery::mock(SqsClientService::class);
        // Expect sendBatch to be called exactly 2 times (3 messages / batch size 2)
        $sqsClientMock->shouldReceive('sendBatch')->times(2);

        $buffer = new SqsBuffer($sqsClientMock);

        $buffer->addRequest(['id' => 1]);
        $buffer->addRequest(['id' => 2]);
        $buffer->addRequest(['id' => 3]);

        $buffer->flush();

        // Buffer should be empty after flush
        $reflection = new \ReflectionClass($buffer);
        $property = $reflection->getProperty('messages');
        $messages = $property->getValue($buffer);

        $this->assertEmpty($messages);
    }
}
