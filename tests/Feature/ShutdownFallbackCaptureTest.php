<?php

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Mockery;
use Pablocarvalho\SqsTelemetry\Services\RequestSanitizer;
use Pablocarvalho\SqsTelemetry\Services\Sampler;
use Pablocarvalho\SqsTelemetry\Services\ServerRequestSnapshot;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Services\SqsClientService;
use Pablocarvalho\SqsTelemetry\Services\TimelineContext;
use Pablocarvalho\SqsTelemetry\SqsTelemetryServiceProvider;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;
use ReflectionMethod;

/**
 * Covers the requests that never reach the HTTP kernel.
 *
 * Acorn bootstraps the kernel for every request and then hands `/wp-admin`,
 * `/wp-json`, `wp-login.php` and any `.php` path back to WordPress, so the
 * middleware never runs on them. Without this fallback those requests — often
 * the busiest on the site — leave no trace in the queue.
 */
class ShutdownFallbackCaptureTest extends TestCase
{
    /**
     * @var array
     */
    protected $originalServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_HOST'] = 'universidade.test';
        $_SERVER['REQUEST_URI'] = '/wp/wp-admin/admin-ajax.php?action=heartbeat';

        config([
            'sqs-telemetry.enabled' => true,
            'sqs-telemetry.project' => 'uc',
            'sqs-telemetry.capture.fallback_to_shutdown' => true,
        ]);

        // The suite runs on the CLI SAPI; the fallback only fires for web
        // requests, so the snapshot has to report a web one.
        $this->app->instance(ServerRequestSnapshot::class, $this->webSnapshot());
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;

        parent::tearDown();
    }

    protected function webSnapshot(): ServerRequestSnapshot
    {
        return new class(new RequestSanitizer(), new TimelineContext()) extends ServerRequestSnapshot
        {
            protected function sapi(): string
            {
                return 'fpm-fcgi';
            }
        };
    }

    protected function freshBuffer(): SqsBuffer
    {
        $client = Mockery::mock(SqsClientService::class);
        $client->shouldReceive('sendBatch')->andReturnNull();

        $buffer = new SqsBuffer($client);
        $this->app->instance(SqsBuffer::class, $buffer);

        return $buffer;
    }

    protected function captureUnroutedRequest(): bool
    {
        $provider = $this->app->getProvider(SqsTelemetryServiceProvider::class);

        $method = new ReflectionMethod($provider, 'captureUnroutedRequest');
        $method->setAccessible(true);

        return (bool) $method->invoke($provider);
    }

    protected function bufferedMessages(SqsBuffer $buffer): array
    {
        $property = (new \ReflectionClass($buffer))->getProperty('messages');
        $property->setAccessible(true);

        return $property->getValue($buffer);
    }

    public function test_it_records_a_request_the_kernel_never_handled()
    {
        $buffer = $this->freshBuffer();

        $this->assertTrue($this->captureUnroutedRequest());

        $messages = $this->bufferedMessages($buffer);

        $this->assertCount(1, $messages);
        $this->assertSame('request', $messages[0]['type']);
        $this->assertSame('shutdown', $messages[0]['capture_source']);
        $this->assertSame('uc', $messages[0]['project']);
        $this->assertStringContainsString('admin-ajax.php', $messages[0]['url']);
    }

    public function test_it_stays_out_of_the_way_when_the_middleware_already_recorded()
    {
        $buffer = $this->freshBuffer();
        $buffer->addRequest(['url' => 'http://universidade.test/cursos', 'capture_source' => 'middleware']);

        $this->assertFalse($this->captureUnroutedRequest());

        $messages = $this->bufferedMessages($buffer);

        $this->assertCount(1, $messages);
        $this->assertSame('middleware', $messages[0]['capture_source']);
    }

    public function test_a_flushed_middleware_request_still_blocks_the_fallback()
    {
        $buffer = $this->freshBuffer();
        $buffer->addRequest(['url' => 'http://universidade.test/cursos']);
        $buffer->flush();

        // An empty buffer is not evidence that the request went unrecorded.
        $this->assertFalse($this->captureUnroutedRequest());
        $this->assertEmpty($this->bufferedMessages($buffer));
    }

    public function test_it_does_nothing_when_the_fallback_is_disabled()
    {
        config(['sqs-telemetry.capture.fallback_to_shutdown' => false]);

        $buffer = $this->freshBuffer();

        $this->assertFalse($this->captureUnroutedRequest());
        $this->assertEmpty($this->bufferedMessages($buffer));
    }

    public function test_it_does_nothing_when_telemetry_is_disabled()
    {
        config(['sqs-telemetry.enabled' => false]);

        $buffer = $this->freshBuffer();

        $this->assertFalse($this->captureUnroutedRequest());
        $this->assertEmpty($this->bufferedMessages($buffer));
    }

    public function test_it_respects_a_negative_sampling_draw()
    {
        config(['sqs-telemetry.sampling.rate' => 0.0]);

        // Console runs bypass sampling by design, so the sampler also has to
        // believe it is serving the web for this to mean anything.
        $this->app->instance(Sampler::class, new class extends Sampler
        {
            protected function runningInConsole(): bool
            {
                return false;
            }
        });

        $buffer = $this->freshBuffer();

        $this->assertFalse($this->captureUnroutedRequest());
        $this->assertEmpty($this->bufferedMessages($buffer));
    }
}
