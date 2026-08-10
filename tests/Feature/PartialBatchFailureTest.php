<?php

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Mockery;
use Pablocarvalho\SqsTelemetry\Services\SqsClientService;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;
use ReflectionProperty;

/**
 * `SendMessageBatch` answers 200 while refusing individual entries.
 *
 * Nothing throws, so a caller that ignores the response loses those messages
 * with no trace at all — the failure mode this covers.
 */
class PartialBatchFailureTest extends TestCase
{
    protected function service($client): SqsClientService
    {
        config([
            'sqs-telemetry.enabled' => true,
            'sqs-telemetry.queue.url' => 'https://sqs.us-east-1.amazonaws.com/1/q',
        ]);

        $service = new SqsClientService();

        // Skip client construction: the point here is how the response is read.
        foreach (['client' => $client, 'clientResolved' => true] as $name => $value) {
            $property = new ReflectionProperty(SqsClientService::class, $name);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }

        return $service;
    }

    public function test_it_reports_the_entries_sqs_refused()
    {
        $client = Mockery::mock();
        $client->shouldReceive('sendMessageBatch')->once()->andReturnUsing(function ($args) {
            // Refuse the second of three entries.
            return ['Failed' => [['Id' => $args['Entries'][1]['Id'], 'Code' => 'Throttling']]];
        });

        $rejected = $this->service($client)->send([
            ['message_id' => 'a'],
            ['message_id' => 'b'],
            ['message_id' => 'c'],
        ]);

        $this->assertCount(1, $rejected);
        $this->assertSame('b', $rejected[0]['message_id']);
    }

    public function test_a_fully_accepted_batch_reports_nothing()
    {
        $client = Mockery::mock();
        $client->shouldReceive('sendMessageBatch')->once()->andReturn(['Failed' => []]);

        $rejected = $this->service($client)->send([['message_id' => 'a'], ['message_id' => 'b']]);

        $this->assertSame([], $rejected);
    }

    public function test_a_call_that_throws_reports_every_message_as_unsent()
    {
        $client = Mockery::mock();
        $client->shouldReceive('sendMessageBatch')->once()->andThrow(new \RuntimeException('rede caiu'));

        $messages = [['message_id' => 'a'], ['message_id' => 'b']];

        // Nothing arrived, so the drainer must be able to put all of it back.
        $this->assertCount(2, $this->service($client)->send($messages));
    }

    public function test_a_message_that_cannot_be_encoded_is_reported_not_dropped()
    {
        $client = Mockery::mock();
        $client->shouldReceive('sendMessageBatch')->andReturn(['Failed' => []]);

        // Invalid UTF-8 makes json_encode fail even after shedding fields.
        $rejected = $this->service($client)->send([
            ['message_id' => 'ok'],
            ['message_id' => "\xB1\x31", 'url' => "\xB1\x31"],
        ]);

        $this->assertCount(1, $rejected);
    }
}
