<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Pablocarvalho\SqsTelemetry\Services\TimelineContext;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;

class TimelineContextTest extends TestCase
{
    public function test_it_adds_events_correctly()
    {
        $context = new TimelineContext();
        $context->startRequest();
        
        $context->addEvent('db_query', 'SELECT * FROM users', 12.5);
        $timeline = $context->getTimeline();
        
        $this->assertCount(2, $timeline); // 1 start request + 1 db_query
        $this->assertEquals('request_start', $timeline[0]['type']);
        $this->assertEquals('db_query', $timeline[1]['type']);
        $this->assertEquals('SELECT * FROM users', $timeline[1]['description']);
        $this->assertEquals(12.5, $timeline[1]['duration_ms']);
    }

    public function test_it_flushes_timeline()
    {
        $context = new TimelineContext();
        $context->addEvent('test_event', 'Test', 0);
        $this->assertCount(1, $context->getTimeline());

        $context->flush();
        $this->assertCount(0, $context->getTimeline());
    }

    public function test_db_bindings_not_captured_by_default()
    {
        config()->set('sqs-telemetry.timeline.db', true);
        config()->set('sqs-telemetry.timeline.db_bindings', false);

        $timelineContext = $this->app->make(TimelineContext::class);
        $timelineContext->startRequest();

        // Simulate a QueryExecuted event
        $connection = $this->app['db']->connection();
        event(new QueryExecuted(
            'select * from users where id = ?',
            [1],
            5.0,
            $connection
        ));

        $timeline = $timelineContext->getTimeline();
        $dbEvent = collect($timeline)->firstWhere('type', 'db_query');

        $this->assertNotNull($dbEvent);
        $this->assertArrayNotHasKey('bindings', $dbEvent['context']);
    }

    public function test_db_bindings_captured_when_enabled()
    {
        config()->set('sqs-telemetry.timeline.db', true);
        config()->set('sqs-telemetry.timeline.db_bindings', true);
        
        $timelineContext = $this->app->make(TimelineContext::class);
        $timelineContext->startRequest();

        $connection = $this->app['db']->connection();
        event(new QueryExecuted(
            'select * from users where id = ?',
            [42],
            3.5,
            $connection
        ));

        $timeline = $timelineContext->getTimeline();
        $dbEvent = collect($timeline)->firstWhere('type', 'db_query');

        $this->assertNotNull($dbEvent);
        $this->assertArrayHasKey('bindings', $dbEvent['context']);
        $this->assertEquals([42], $dbEvent['context']['bindings']);
    }

    public function test_sensitive_bindings_are_redacted()
    {
        config()->set('sqs-telemetry.timeline.db', true);
        config()->set('sqs-telemetry.timeline.db_bindings', true);

        $timelineContext = $this->app->make(TimelineContext::class);
        $timelineContext->startRequest();

        $connection = $this->app['db']->connection();
        event(new QueryExecuted(
            'insert into "users" ("name", "email", "password", "cpf") values (?, ?, ?, ?)',
            ['John', 'john@example.com', 'secret123', '123.456.789-00'],
            2.0,
            $connection
        ));

        $timeline = $timelineContext->getTimeline();
        $dbEvent = collect($timeline)->firstWhere('type', 'db_query');

        $this->assertNotNull($dbEvent);
        $this->assertEquals('John', $dbEvent['context']['bindings'][0]);
        $this->assertEquals('john@example.com', $dbEvent['context']['bindings'][1]);
        $this->assertEquals('[REDACTED]', $dbEvent['context']['bindings'][2]); // password
        $this->assertEquals('[REDACTED]', $dbEvent['context']['bindings'][3]); // cpf
    }

    public function test_db_query_source_location_does_not_error_when_enabled()
    {
        config()->set('sqs-telemetry.timeline.db', true);
        config()->set('sqs-telemetry.timeline.db_source_location', true);

        $timelineContext = $this->app->make(TimelineContext::class);
        $timelineContext->startRequest();

        $connection = $this->app['db']->connection();
        event(new QueryExecuted(
            'select * from users where id = ?',
            [1],
            5.0,
            $connection
        ));

        $timeline = $timelineContext->getTimeline();
        $dbEvent = collect($timeline)->firstWhere('type', 'db_query');

        // The query event should be captured successfully regardless of
        // whether source location is resolved (in test env it won't find
        // an app-level frame, so source keys may be absent — that's OK).
        $this->assertNotNull($dbEvent);
        $this->assertEquals('select * from users where id = ?', $dbEvent['description']);
    }

    public function test_db_query_source_location_keys_format_when_present()
    {
        // Directly test the addEvent + metadata format to ensure
        // that when source IS resolved, the keys have the right types.
        $timelineContext = $this->app->make(TimelineContext::class);

        $timelineContext->addEvent('db_query', 'select 1', 1.0, [
            'connection'  => 'mysql',
            'database'    => 'test',
            'source_file' => 'app/Http/Controllers/UserController.php',
            'source_line' => 42,
        ]);

        $timeline = $timelineContext->getTimeline();
        $dbEvent = $timeline[0];

        $this->assertEquals('app/Http/Controllers/UserController.php', $dbEvent['context']['source_file']);
        $this->assertEquals(42, $dbEvent['context']['source_line']);
    }

    public function test_db_query_does_not_capture_source_location_when_disabled()
    {
        config()->set('sqs-telemetry.timeline.db', true);
        config()->set('sqs-telemetry.timeline.db_source_location', false);

        $timelineContext = $this->app->make(TimelineContext::class);
        $timelineContext->startRequest();

        $connection = $this->app['db']->connection();
        event(new QueryExecuted(
            'select * from users where id = ?',
            [1],
            5.0,
            $connection
        ));

        $timeline = $timelineContext->getTimeline();
        $dbEvent = collect($timeline)->firstWhere('type', 'db_query');

        $this->assertNotNull($dbEvent);
        $this->assertArrayNotHasKey('source_file', $dbEvent['context']);
        $this->assertArrayNotHasKey('source_line', $dbEvent['context']);
    }
}
