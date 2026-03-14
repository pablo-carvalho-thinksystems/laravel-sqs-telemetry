<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

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
}
