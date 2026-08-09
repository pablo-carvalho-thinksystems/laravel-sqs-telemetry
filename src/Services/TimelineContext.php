<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

class TimelineContext
{
    /**
     * @var array
     */
    protected $timeline = [];

    /**
     * Events discarded after the cap was reached.
     *
     * @var int
     */
    protected $droppedEvents = 0;

    /**
     * Set the start of the request, clearing any previous state.
     */
    public function startRequest(): void
    {
        $this->flush();

        // LARAVEL_START is defined by Laravel on boot.
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $timeString = \DateTime::createFromFormat('U.u', sprintf('%.6f', $startTime));

        $this->addEvent(
            'request_start',
            'Request started',
            0.0,
            [],
            $timeString ? $timeString->format('Y-m-d\TH:i:s.uP') : now()->toIso8601String()
        );
    }

    /**
     * Add an event to the timeline.
     *
     * @param string $type
     * @param string $description
     * @param float $durationMs
     * @param array $context
     * @param string|null $timestamp ISO8601 formatted timestamp, defaults to now.
     */
    public function addEvent(string $type, string $description, float $durationMs, array $context = [], ?string $timestamp = null): void
    {
        $max = (int) config('sqs-telemetry.limits.max_timeline_events', 200);

        if ($max > 0 && count($this->timeline) >= $max) {
            $this->droppedEvents++;

            return;
        }

        $this->timeline[] = [
            'type' => $type,
            'description' => $description,
            'duration_ms' => round($durationMs, 2),
            'context' => $context,
            'timestamp' => $timestamp ?? now()->toIso8601String(),
        ];
    }

    /**
     * Get the current timeline events.
     *
     * @return array
     */
    public function getTimeline(): array
    {
        if ($this->droppedEvents === 0) {
            return $this->timeline;
        }

        // A truncated timeline that does not say so reads as a complete one,
        // and "this request ran 200 queries" is a very different conclusion
        // from "this request ran 200 queries that we bothered to record".
        $timeline = $this->timeline;
        $timeline[] = [
            'type'        => 'timeline_truncated',
            'description' => "Timeline truncated: {$this->droppedEvents} event(s) dropped",
            'duration_ms' => 0.0,
            'context'     => ['dropped' => $this->droppedEvents],
            'timestamp'   => now()->toIso8601String(),
        ];

        return $timeline;
    }

    /**
     * Number of events discarded after the cap was reached.
     *
     * @return int
     */
    public function droppedEvents(): int
    {
        return $this->droppedEvents;
    }

    /**
     * Flush the timeline, ensuring it's empty for the next request.
     * Essential for long-running processes like Octane.
     */
    public function flush(): void
    {
        $this->timeline = [];
        $this->droppedEvents = 0;
    }
}
