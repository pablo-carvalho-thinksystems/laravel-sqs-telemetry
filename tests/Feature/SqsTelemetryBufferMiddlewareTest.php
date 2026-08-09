<?php

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Pablocarvalho\SqsTelemetry\Middleware\SqsTelemetryBufferMiddleware;
use Pablocarvalho\SqsTelemetry\Services\RequestSanitizer;
use Pablocarvalho\SqsTelemetry\Services\Sampler;
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

        $middleware = new SqsTelemetryBufferMiddleware($bufferMock, $timelineMock, new Sampler(), new RequestSanitizer());

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

        // Disabled means the timeline is never even started: the point of the
        // switch is that an off request pays nothing.
        $timelineMock = Mockery::mock(TimelineContext::class);
        $timelineMock->shouldReceive('startRequest')->never();

        $middleware = new SqsTelemetryBufferMiddleware($bufferMock, $timelineMock, new Sampler(), new RequestSanitizer());
        $request = Request::create('http://localhost/test', 'GET');

        $response = $middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });

        $this->assertEquals(200, $response->getStatusCode());
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

        $middleware = new SqsTelemetryBufferMiddleware($bufferMock, $timelineMock, new Sampler(), new RequestSanitizer());

        $request = Request::create('http://localhost/test', 'GET');
        $request->headers->set('Authorization', 'Bearer secret123');
        $request->headers->set('Cookie', 'session_id=12345');
        $request->headers->set('X-Custom-Header', 'custom_value');

        $middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });
    }

    public function test_middleware_defers_the_request_entry_when_configured()
    {
        config([
            'sqs-telemetry.enabled' => true,
            'sqs-telemetry.project' => 'test-project',
            'sqs-telemetry.capture.defer_request_to_flush' => true,
        ]);

        $bufferMock = Mockery::mock(SqsBuffer::class);
        $bufferMock->shouldReceive('addRequest')->never();
        $bufferMock->shouldReceive('deferRequest')->once()->with(Mockery::type('callable'));

        // Nothing is measured on the way out: duration, status and timeline are
        // only read when the deferred factory runs at flush time.
        $timelineMock = Mockery::mock(TimelineContext::class);
        $timelineMock->shouldReceive('startRequest')->once();
        $timelineMock->shouldReceive('addEvent')->never();
        $timelineMock->shouldReceive('getTimeline')->never();

        $middleware = new SqsTelemetryBufferMiddleware($bufferMock, $timelineMock, new Sampler(), new RequestSanitizer());

        $response = $middleware->handle(
            Request::create('http://localhost/test', 'GET'),
            function ($req) {
                return new Response('OK', 200);
            }
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_deferred_factory_builds_the_entry_when_invoked()
    {
        config([
            'sqs-telemetry.enabled' => true,
            'sqs-telemetry.project' => 'test-project',
            'sqs-telemetry.capture.defer_request_to_flush' => true,
        ]);

        $factory = null;

        $bufferMock = Mockery::mock(SqsBuffer::class);
        $bufferMock->shouldReceive('deferRequest')->once()->andReturnUsing(function ($callback) use (&$factory) {
            $factory = $callback;
        });

        $timelineMock = Mockery::mock(TimelineContext::class);
        $timelineMock->shouldReceive('startRequest')->once();
        $timelineMock->shouldReceive('addEvent')->once();
        $timelineMock->shouldReceive('getTimeline')->once()->andReturn([['type' => 'db_query']]);

        $middleware = new SqsTelemetryBufferMiddleware($bufferMock, $timelineMock, new Sampler(), new RequestSanitizer());

        $middleware->handle(
            Request::create('http://localhost/test', 'GET'),
            function ($req) {
                return new Response('OK', 200);
            }
        );

        $this->assertIsCallable($factory);

        $data = $factory();

        $this->assertSame('test-project', $data['project']);
        $this->assertSame('http://localhost/test', $data['url']);
        $this->assertArrayHasKey('execution_time', $data);
        $this->assertSame([['type' => 'db_query']], $data['timeline']);
    }
}
