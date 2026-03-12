<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Handlers;

use Pablocarvalho\SqsTelemetry\Services\AiExceptionAnalyzer;
use Pablocarvalho\SqsTelemetry\Services\CodeContextFetcher;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Throwable;

class SqsExceptionHandler
{
    /**
     * @var SqsBuffer
     */
    protected $buffer;

    /**
     * @var CodeContextFetcher
     */
    protected $contextFetcher;

    /**
     * @var AiExceptionAnalyzer
     */
    protected $aiAnalyzer;

    public function __construct(
        SqsBuffer $buffer,
        CodeContextFetcher $contextFetcher,
        AiExceptionAnalyzer $aiAnalyzer
    ) {
        $this->buffer = $buffer;
        $this->contextFetcher = $contextFetcher;
        $this->aiAnalyzer = $aiAnalyzer;
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
        $context = $this->contextFetcher->fetchContext($e);
        $aiReport = null;

        if ($this->aiAnalyzer->isEnabled()) {
            $aiReport = $this->aiAnalyzer->generateReport($e, $context);
        }

        $this->buffer->addException([
            'class'       => get_class($e),
            'message'     => $e->getMessage(),
            'file'        => $e->getFile(),
            'line'        => $e->getLine(),
            'url'         => $request ? $request->fullUrl() : 'Console/Cli',
            'method'      => $request ? $request->method() : null,
            'timestamp'   => now()->toIso8601String(),
            'payload'     => $request ? $this->filterPayload($request->all()) : null,
            'headers'     => $request ? $this->filterHeaders($request->headers->all()) : null,
            'ai_resolution_report' => $aiReport,
            // Only capture the first 10 lines of the stack trace to not bloat the SQS message size
            'stack_trace' => implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 10)),
        ]);
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

        return array_map(function ($value) {
            return is_array($value) ? implode(', ', $value) : $value;
        }, $headers);
    }

    /**
     * Filters out sensitive payload data.
     *
     * @param array $payload
     * @return array
     */
    protected function filterPayload(array $payload): array
    {
        $sensitive = ['password', 'password_confirmation', 'token', 'secret', 'authorization'];

        foreach ($sensitive as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = '********';
            }
        }

        return $payload;
    }
}
