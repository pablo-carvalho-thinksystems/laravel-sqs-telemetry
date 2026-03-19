<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Log;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Services\TimelineContext;
use Throwable;

class SqsCommandListener
{
    /**
     * @var SqsBuffer
     */
    protected $buffer;

    /**
     * @var TimelineContext
     */
    protected $timelineContext;

    /**
     * @var float|null
     */
    protected $startTime;

    /**
     * Commands that should be excluded from telemetry to avoid noise.
     *
     * @var array
     */
    protected $excludedCommands = [
        'schedule:run',
        'schedule:finish',
        'queue:work',
        'queue:listen',
        'queue:restart',
        'package:discover',
        'vendor:publish',
        'cache:clear',
        'config:cache',
        'config:clear',
        'route:cache',
        'route:clear',
        'view:cache',
        'view:clear',
        'event:cache',
        'event:clear',
        'optimize',
        'optimize:clear',
    ];

    public function __construct(SqsBuffer $buffer, TimelineContext $timelineContext)
    {
        $this->buffer = $buffer;
        $this->timelineContext = $timelineContext;
    }

    /**
     * Handle the CommandStarting event.
     *
     * @param CommandStarting $event
     * @return void
     */
    public function handleStarting(CommandStarting $event): void
    {
        if (!config('sqs-telemetry.enabled', true)) {
            return;
        }

        if ($this->isExcluded($event->command)) {
            return;
        }

        $this->startTime = microtime(true);

        // Reset timeline for this command execution
        $this->timelineContext->startRequest();

        $this->timelineContext->addEvent('command_start', "Command: {$event->command}", 0.0, [
            'command' => $event->command,
        ]);
    }

    /**
     * Handle the CommandFinished event.
     *
     * @param CommandFinished $event
     * @return void
     */
    public function handleFinished(CommandFinished $event): void
    {
        if (!config('sqs-telemetry.enabled', true)) {
            return;
        }

        if ($this->isExcluded($event->command)) {
            return;
        }

        // If startTime is null, command was not tracked (e.g. excluded at start)
        if ($this->startTime === null) {
            return;
        }

        try {
            $executionTime = round((microtime(true) - $this->startTime) * 1000, 2); // ms

            $this->timelineContext->addEvent('command_finished', "Command finished: {$event->command}", $executionTime, [
                'command'   => $event->command,
                'exit_code' => $event->exitCode,
            ]);

            $this->buffer->addCommand([
                'project'        => config('sqs-telemetry.project', 'laravel-app'),
                'command'        => $event->command,
                'exit_code'      => $event->exitCode,
                'execution_time' => $executionTime,
                'timestamp'      => now()->toIso8601String(),
                'timeline'       => $this->timelineContext->getTimeline(),
            ]);

            // Flush immediately — in older Laravel versions (e.g., 6.x),
            // app()->terminating() may not reliably fire in console context.
            $this->buffer->flush();
            $this->timelineContext->flush();
        } catch (Throwable $e) {
            Log::error('SqsTelemetry: Failed to report command telemetry.', [
                'error'   => $e->getMessage(),
                'command' => $event->command,
            ]);
        } finally {
            $this->startTime = null;
        }
    }

    /**
     * Check if a command should be excluded from telemetry.
     *
     * @param string|null $command
     * @return bool
     */
    protected function isExcluded(?string $command): bool
    {
        if ($command === null) {
            return true;
        }

        return in_array($command, $this->excludedCommands, true);
    }
}
