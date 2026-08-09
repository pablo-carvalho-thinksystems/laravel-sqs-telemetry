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

    public function test_the_request_latch_survives_a_flush()
    {
        $sqsClientMock = Mockery::mock(SqsClientService::class);
        $sqsClientMock->shouldReceive('sendBatch');

        $buffer = new SqsBuffer($sqsClientMock);

        $this->assertFalse($buffer->hasRecordedRequest());

        $buffer->addRequest(['url' => 'http://localhost/test']);
        $buffer->flush();

        // The shutdown fallback runs after the buffer has already drained, so
        // an empty buffer must not read as "this request went unrecorded".
        $this->assertTrue($buffer->hasRecordedRequest());
    }

    public function test_a_deferred_request_counts_as_recorded()
    {
        $sqsClientMock = Mockery::mock(SqsClientService::class);
        $buffer = new SqsBuffer($sqsClientMock);

        $buffer->deferRequest(function () {
            return ['url' => 'http://localhost/test'];
        });

        $this->assertTrue($buffer->hasRecordedRequest());
    }

    public function test_resetting_request_state_clears_the_latch()
    {
        $sqsClientMock = Mockery::mock(SqsClientService::class);
        $buffer = new SqsBuffer($sqsClientMock);

        $buffer->addRequest(['url' => 'http://localhost/test']);
        $buffer->resetRequestState();

        $this->assertFalse($buffer->hasRecordedRequest());
    }

    public function test_a_deferred_request_is_materialised_only_once()
    {
        $calls = 0;

        $sqsClientMock = Mockery::mock(SqsClientService::class);
        $sqsClientMock->shouldReceive('sendBatch')->once();

        $buffer = new SqsBuffer($sqsClientMock);

        $buffer->deferRequest(function () use (&$calls) {
            $calls++;

            return ['url' => 'http://localhost/test'];
        });

        // Both `terminating()` and the shutdown function drain the buffer.
        $buffer->flush();
        $buffer->flush();

        $this->assertSame(1, $calls);
    }
}
