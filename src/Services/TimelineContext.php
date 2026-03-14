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
        return $this->timeline;
    }

    /**
     * Flush the timeline, ensuring it's empty for the next request.
     * Essential for long-running processes like Octane.
     */
    public function flush(): void
    {
        $this->timeline = [];
    }
}
