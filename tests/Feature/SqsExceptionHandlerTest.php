<?php

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Exception;
use Mockery;
use Pablocarvalho\SqsTelemetry\Handlers\SqsExceptionHandler;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;

class SqsExceptionHandlerTest extends TestCase
{
    public function test_handler_adds_exception_to_buffer()
    {
        config(['sqs-telemetry.enabled' => true]);
        
        $bufferMock = Mockery::mock(SqsBuffer::class);
        $bufferMock->shouldReceive('addException')->once()->with(Mockery::on(function ($data) {
            return $data['class'] === Exception::class
                && $data['message'] === 'Test Exception'
                && isset($data['stack_trace']);
        }));

        $handler = new SqsExceptionHandler($bufferMock);
        $handler->report(new Exception('Test Exception'));
    }

    public function test_handler_does_not_add_when_disabled()
    {
        config(['sqs-telemetry.enabled' => false]);
        
        $bufferMock = Mockery::mock(SqsBuffer::class);
        $bufferMock->shouldReceive('addException')->never();

        $handler = new SqsExceptionHandler($bufferMock);
        $handler->report(new Exception('Test Exception'));
    }
}
