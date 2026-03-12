<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Symfony\Component\HttpFoundation\Response;

class SqsTelemetryBufferMiddleware
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
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        if (config('sqs-telemetry.enabled', true)) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2); // ms

            $this->buffer->addRequest([
                'url'            => $request->fullUrl(),
                'method'         => $request->method(),
                'ip'             => $request->ip(),
                'user_agent'     => $request->userAgent(),
                'status_code'    => $response->getStatusCode(),
                'execution_time' => $executionTime,
                'timestamp'      => now()->toIso8601String(),
                'headers'        => $this->filterHeaders($request->headers->all()),
            ]);
        }

        return $response;
    }

    /**
     * Filters out sensitive headers (like authorization tokens).
     *
     * @param array $headers
     * @return array
     */
    protected function filterHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'cookie', 'php-auth-pw'];

        foreach ($sensitive as $key) {
            unset($headers[$key]);
            unset($headers[strtolower($key)]);
        }

        // Return flattened headers if multiple values exist for the same key
        return array_map(function ($value) {
            return is_array($value) ? implode(', ', $value) : $value;
        }, $headers);
    }
}
