<?php

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Pablocarvalho\SqsTelemetry\Middleware\SqsTelemetryBufferMiddleware;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Services\TimelineContext;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;

class SqsTelemetryBufferMiddlewareTest extends TestCase
{
    public function test_middleware_adds_request_to_buffer()
    {
        // Force config to true just in case
        config([
            'sqs-telemetry.enabled' => true,
            'sqs-telemetry.project' => 'test-project',
        ]);

        $bufferMock = Mockery::mock(SqsBuffer::class);
        $bufferMock->shouldReceive('addRequest')->once()->with(Mockery::on(function ($data) {
            return $data['project'] === 'test-project'
                && $data['url'] === 'http://localhost/test'
                && $data['method'] === 'GET'
                && $data['status_code'] === 200
                && isset($data['execution_time'])
                && isset($data['headers']);
        }));

        $timelineMock = Mockery::mock(TimelineContext::class);
        $timelineMock->shouldReceive('startRequest')->once();
        $timelineMock->shouldReceive('addEvent')->once();
        $timelineMock->shouldReceive('getTimeline')->once()->andReturn([]);

        $middleware = new SqsTelemetryBufferMiddleware($bufferMock, $timelineMock);

        $request = Request::create('http://localhost/test', 'GET');
        
        $response = $middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_middleware_does_not_add_when_disabled()
    {
        config(['sqs-telemetry.enabled' => false]);
        
        $bufferMock = Mockery::mock(SqsBuffer::class);
        $bufferMock->shouldReceive('addRequest')->never();

        $timelineMock = Mockery::mock(TimelineContext::class);
        $timelineMock->shouldReceive('startRequest')->once();

        $middleware = new SqsTelemetryBufferMiddleware($bufferMock, $timelineMock);
        $request = Request::create('http://localhost/test', 'GET');
        
        $middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });
    }

    public function test_middleware_filters_sensitive_headers()
    {
        config([
            'sqs-telemetry.enabled' => true,
            'sqs-telemetry.project' => 'test-project',
        ]);

        $bufferMock = Mockery::mock(SqsBuffer::class);
        $bufferMock->shouldReceive('addRequest')->once()->with(Mockery::on(function ($data) {
            return $data['project'] === 'test-project'
                && !isset($data['headers']['authorization'])
                && !isset($data['headers']['cookie'])
                && isset($data['headers']['x-custom-header']);
        }));

        $timelineMock = Mockery::mock(TimelineContext::class);
        $timelineMock->shouldReceive('startRequest')->once();
        $timelineMock->shouldReceive('addEvent')->once();
        $timelineMock->shouldReceive('getTimeline')->once()->andReturn([]);

        $middleware = new SqsTelemetryBufferMiddleware($bufferMock, $timelineMock);

        $request = Request::create('http://localhost/test', 'GET');
        $request->headers->set('Authorization', 'Bearer secret123');
        $request->headers->set('Cookie', 'session_id=12345');
        $request->headers->set('X-Custom-Header', 'custom_value');
        
        $middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });
    }
}
