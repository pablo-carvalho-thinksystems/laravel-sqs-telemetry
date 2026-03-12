<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Handlers;

use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Throwable;

class SqsExceptionHandler
{
    /**
     * @var SqsBuffer
     */
    protected $buffer;

    public function __construct(SqsBuffer $buffer)
    {
        $this->buffer = $buffer;
    }

    /**
     * Reports an exception to the SqsBuffer for delayed batch shipping.
     *
     * @param Throwable $e
     * @return void
     */
    public function report(Throwable $e): void
    {
        if (!config('sqs-telemetry.enabled', true)) {
            return;
        }

        $request = request();

        $this->buffer->addException([
            'class'       => get_class($e),
            'message'     => $e->getMessage(),
            'file'        => $e->getFile(),
            'line'        => $e->getLine(),
            'url'         => $request ? $request->fullUrl() : 'Console/Cli',
            'method'      => $request ? $request->method() : null,
            'timestamp'   => now()->toIso8601String(),
            // Only capture the first 10 lines of the stack trace to not bloat the SQS message size
            'stack_trace' => implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 10)),
        ]);
    }
}
