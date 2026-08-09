<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pablocarvalho\SqsTelemetry\Services\RequestSanitizer;
use Pablocarvalho\SqsTelemetry\Services\Sampler;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Services\TimelineContext;
use Symfony\Component\HttpFoundation\Response;

class SqsTelemetryBufferMiddleware
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
     * @var Sampler
     */
    protected $sampler;

    /**
     * @var RequestSanitizer
     */
    protected $sanitizer;

    public function __construct(
        SqsBuffer $buffer,
        TimelineContext $timelineContext,
        Sampler $sampler,
        RequestSanitizer $sanitizer
    ) {
        $this->buffer = $buffer;
        $this->timelineContext = $timelineContext;
        $this->sampler = $sampler;
        $this->sanitizer = $sanitizer;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (! $this->sampler->shouldRecord()) {
            return $next($request);
        }

        // Start the timeline recording
        $this->timelineContext->startRequest();

        $startTime = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $base = [
            'project'    => config('sqs-telemetry.project', 'laravel-app'),
            'url'        => $request->fullUrl(),
            'method'     => $request->method(),
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp'  => now()->toIso8601String(),
            'payload'    => $this->sanitizer->payload($request->all()),
            'headers'    => $this->sanitizer->headers($request->headers->all()),
            'capture_source' => 'middleware',
        ];

        if (config('sqs-telemetry.capture.defer_request_to_flush', false)) {
            $this->deferRequest($base, $startTime);

            return $response;
        }

        $executionTime = round((microtime(true) - $startTime) * 1000, 2); // ms

        // Add an explicit response event to the timeline before sending
        $this->timelineContext->addEvent('response_sent', 'HTTP Response Sent', 0.0, [
            'status_code' => $response->getStatusCode(),
        ]);

        $this->buffer->addRequest($base + [
            'status_code'    => $response->getStatusCode(),
            'execution_time' => $executionTime,
            'timeline'       => $this->timelineContext->getTimeline(),
        ]);

        return $response;
    }

    /**
     * Assemble the request entry when the buffer drains instead of now.
     *
     * On hosts that finish producing the response after Laravel's middleware
     * stack has unwound — Acorn serving a WordPress page, where the kernel runs
     * on `after_setup_theme` and WordPress renders afterwards — measuring here
     * would time the kernel pass and miss the page. Everything this middleware
     * knows is captured now; duration, status and timeline are read at flush.
     *
     * @param array $base
     * @param float $startTime
     * @return void
     */
    protected function deferRequest(array $base, float $startTime): void
    {
        $timelineContext = $this->timelineContext;

        $this->buffer->deferRequest(function () use ($base, $startTime, $timelineContext) {
            $statusCode = function_exists('http_response_code') ? http_response_code() : null;
            $statusCode = is_int($statusCode) ? $statusCode : null;

            $timelineContext->addEvent('response_sent', 'HTTP Response Sent', 0.0, [
                'status_code' => $statusCode,
            ]);

            return $base + [
                'status_code'    => $statusCode,
                'execution_time' => round((microtime(true) - $startTime) * 1000, 2),
                'timeline'       => $timelineContext->getTimeline(),
            ];
        });
    }

}
