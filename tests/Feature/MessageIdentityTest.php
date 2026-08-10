<?php

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Exception;
use Mockery;
use Pablocarvalho\SqsTelemetry\Services\RequestIdentity;
use Pablocarvalho\SqsTelemetry\Services\Sampler;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Services\SqsClientService;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;

/**
 * Fields a consumer cannot reconstruct on its own.
 *
 * SQS delivers at least once, and the drainer re-sends a batch after a partial
 * failure, so duplicates are normal rather than exceptional — without a stable
 * id per message a dashboard counts the same request twice. And under sampling
 * every recorded request stands for several, so a count is only meaningful
 * alongside the weight it was captured with.
 */
class MessageIdentityTest extends TestCase
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected $sent = [];

    protected function buffer(): SqsBuffer
    {
        $this->sent = [];

        $client = Mockery::mock(SqsClientService::class);
        $client->shouldReceive('sendBatch')->andReturnUsing(function ($batch) {
            foreach ($batch as $message) {
                $this->sent[] = $message;
            }
        });

        return new SqsBuffer($client);
    }

    public function test_every_message_carries_a_unique_id()
    {
        $buffer = $this->buffer();

        $buffer->addRequest(['url' => '/a']);
        $buffer->addException(['message' => 'boom']);
        $buffer->flush();

        $this->assertCount(2, $this->sent);

        $ids = array_column($this->sent, 'message_id');

        $this->assertCount(2, array_filter($ids));
        $this->assertNotSame($ids[0], $ids[1], 'ids devem diferir por mensagem');
    }

    public function test_messages_from_one_request_share_a_request_id()
    {
        $buffer = $this->buffer();

        $buffer->addRequest(['url' => '/a']);
        $buffer->addException(['message' => 'boom']);
        $buffer->flush();

        $this->assertSame(
            $this->sent[0]['request_id'],
            $this->sent[1]['request_id'],
            'a request e a exception dela pertencem à mesma história'
        );
    }

    public function test_the_request_id_changes_once_the_request_ends()
    {
        $buffer = $this->buffer();

        $buffer->addRequest(['url' => '/a']);
        $buffer->flush();
        $first = $this->sent[0]['request_id'];

        $this->app->make(RequestIdentity::class)->reset();

        $buffer = $this->buffer();
        $buffer->addRequest(['url' => '/b']);
        $buffer->flush();

        $this->assertNotSame($first, $this->sent[0]['request_id']);
    }

    public function test_the_sampling_weight_travels_with_the_message()
    {
        config(['sqs-telemetry.sampling.rate' => 0.05]);

        // Console runs are never sampled, so the sampler has to believe it is
        // serving the web for the configured rate to apply.
        $this->app->instance(Sampler::class, new class extends Sampler
        {
            protected function runningInConsole(): bool
            {
                return false;
            }
        });

        $buffer = $this->buffer();
        $buffer->addRequest(['url' => '/a']);
        $buffer->flush();

        $this->assertEqualsWithDelta(0.05, $this->sent[0]['sampling_rate'], 0.0001);
    }

    public function test_a_forced_request_is_reported_at_full_weight()
    {
        config(['sqs-telemetry.sampling.rate' => 0.05]);

        $sampler = new class extends Sampler
        {
            protected function runningInConsole(): bool
            {
                return false;
            }
        };

        $this->app->instance(Sampler::class, $sampler);

        // An exception promotes the request: it was kept with certainty, not
        // drawn. Weighting it by 0.05 would multiply the error count by twenty.
        $sampler->forceRecord();

        $buffer = $this->buffer();
        $buffer->addException(['message' => (new Exception('boom'))->getMessage()]);
        $buffer->flush();

        $this->assertSame(1.0, $this->sent[0]['sampling_rate']);
    }

    public function test_console_runs_are_reported_at_full_weight()
    {
        config(['sqs-telemetry.sampling.rate' => 0.05]);

        $buffer = $this->buffer();
        $buffer->addCommand(['command' => 'foo:bar']);
        $buffer->flush();

        $this->assertSame(1.0, $this->sent[0]['sampling_rate']);
    }

    public function test_identity_never_overwrites_the_message_own_fields()
    {
        $buffer = $this->buffer();

        $buffer->addRequest(['url' => '/a', 'request_id' => 'fornecido-pelo-chamador']);
        $buffer->flush();

        $this->assertSame('fornecido-pelo-chamador', $this->sent[0]['request_id']);
    }
}
