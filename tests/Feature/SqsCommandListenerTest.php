<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Mockery;
use Pablocarvalho\SqsTelemetry\Listeners\SqsCommandListener;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Services\SqsClientService;
use Pablocarvalho\SqsTelemetry\Services\TimelineContext;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class SqsCommandListenerTest extends TestCase
{
    public function test_it_captures_command_telemetry()
    {
        $sentBatch = null;
        $sqsClientMock = Mockery::mock(SqsClientService::class);
        $sqsClientMock->shouldReceive('sendBatch')->once()->withArgs(function ($batch) use (&$sentBatch) {
            $sentBatch = $batch;
            return true;
        });
        $buffer = new SqsBuffer($sqsClientMock);
        $timelineContext = new TimelineContext();

        $listener = new SqsCommandListener($buffer, $timelineContext);

        // Simulate CommandStarting
        $startingEvent = new CommandStarting(
            'inspire',
            new ArrayInput([]),
            new NullOutput()
        );
        $listener->handleStarting($startingEvent);

        // Simulate CommandFinished
        $finishedEvent = new CommandFinished(
            'inspire',
            new ArrayInput([]),
            new NullOutput(),
            0
        );
        $listener->handleFinished($finishedEvent);

        // Verify the batch sent to SQS
        $this->assertNotNull($sentBatch);
        $this->assertCount(1, $sentBatch);
        $this->assertEquals('command', $sentBatch[0]['type']);
        $this->assertEquals('inspire', $sentBatch[0]['command']);
        $this->assertEquals(0, $sentBatch[0]['exit_code']);
        $this->assertArrayHasKey('execution_time', $sentBatch[0]);
        $this->assertArrayHasKey('timeline', $sentBatch[0]);
        $this->assertIsArray($sentBatch[0]['timeline']);

        // Timeline should have: request_start, command_start, command_finished
        $timeline = $sentBatch[0]['timeline'];
        $this->assertCount(3, $timeline);
        $this->assertEquals('request_start', $timeline[0]['type']);
        $this->assertEquals('command_start', $timeline[1]['type']);
        $this->assertEquals('command_finished', $timeline[2]['type']);
    }

    public function test_it_ignores_excluded_commands()
    {
        $sqsClientMock = Mockery::mock(SqsClientService::class);
        $sqsClientMock->shouldNotReceive('sendBatch');
        $buffer = new SqsBuffer($sqsClientMock);
        $timelineContext = new TimelineContext();

        $listener = new SqsCommandListener($buffer, $timelineContext);

        // Simulate an excluded command (queue:work)
        $startingEvent = new CommandStarting(
            'queue:work',
            new ArrayInput([]),
            new NullOutput()
        );
        $listener->handleStarting($startingEvent);

        $finishedEvent = new CommandFinished(
            'queue:work',
            new ArrayInput([]),
            new NullOutput(),
            0
        );
        $listener->handleFinished($finishedEvent);

        // Buffer should be empty — excluded command was ignored
        $reflection = new \ReflectionClass($buffer);
        $property = $reflection->getProperty('messages');
        $messages = $property->getValue($buffer);

        $this->assertEmpty($messages);
    }

    public function test_it_captures_timeline_events_during_command()
    {
        $sentBatch = null;
        $sqsClientMock = Mockery::mock(SqsClientService::class);
        $sqsClientMock->shouldReceive('sendBatch')->once()->withArgs(function ($batch) use (&$sentBatch) {
            $sentBatch = $batch;
            return true;
        });
        $buffer = new SqsBuffer($sqsClientMock);
        $timelineContext = new TimelineContext();

        $listener = new SqsCommandListener($buffer, $timelineContext);

        // Start command
        $startingEvent = new CommandStarting(
            'app:import-users',
            new ArrayInput([]),
            new NullOutput()
        );
        $listener->handleStarting($startingEvent);

        // Simulate timeline events that would happen during the command
        $timelineContext->addEvent('db_query', 'SELECT * FROM users', 5.2, ['connection' => 'mysql']);
        $timelineContext->addEvent('http_request', 'POST https://api.example.com/notify', 120.0, ['status' => 200]);

        // Finish command
        $finishedEvent = new CommandFinished(
            'app:import-users',
            new ArrayInput([]),
            new NullOutput(),
            0
        );
        $listener->handleFinished($finishedEvent);

        // Verify the batch sent to SQS
        $this->assertNotNull($sentBatch);
        $this->assertCount(1, $sentBatch);
        $timeline = $sentBatch[0]['timeline'];

        // request_start, command_start, db_query, http_request, command_finished
        $this->assertCount(5, $timeline);
        $types = array_column($timeline, 'type');
        $this->assertEquals(['request_start', 'command_start', 'db_query', 'http_request', 'command_finished'], $types);
    }

    public function test_it_captures_failed_command_exit_code()
    {
        $sentBatch = null;
        $sqsClientMock = Mockery::mock(SqsClientService::class);
        $sqsClientMock->shouldReceive('sendBatch')->once()->withArgs(function ($batch) use (&$sentBatch) {
            $sentBatch = $batch;
            return true;
        });
        $buffer = new SqsBuffer($sqsClientMock);
        $timelineContext = new TimelineContext();

        $listener = new SqsCommandListener($buffer, $timelineContext);

        $startingEvent = new CommandStarting(
            'migrate',
            new ArrayInput([]),
            new NullOutput()
        );
        $listener->handleStarting($startingEvent);

        $finishedEvent = new CommandFinished(
            'migrate',
            new ArrayInput([]),
            new NullOutput(),
            1 // non-zero exit code
        );
        $listener->handleFinished($finishedEvent);

        // Verify the batch sent to SQS
        $this->assertNotNull($sentBatch);
        $this->assertCount(1, $sentBatch);
        $this->assertEquals(1, $sentBatch[0]['exit_code']);
    }
}
