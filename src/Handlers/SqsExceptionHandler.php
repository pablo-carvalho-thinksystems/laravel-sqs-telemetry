<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Handlers;

use Illuminate\Support\Facades\Log;
use Pablocarvalho\SqsTelemetry\Services\AiExceptionAnalyzer;
use Pablocarvalho\SqsTelemetry\Services\CodeContextFetcher;
use Pablocarvalho\SqsTelemetry\Services\ReportedExceptions;
use Pablocarvalho\SqsTelemetry\Services\RequestSanitizer;
use Pablocarvalho\SqsTelemetry\Services\Sampler;
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

    /**
     * @var Sampler
     */
    protected $sampler;

    /**
     * @var ReportedExceptions
     */
    protected $reported;

    /**
     * @var RequestSanitizer
     */
    protected $sanitizer;

    public function __construct(
        SqsBuffer $buffer,
        CodeContextFetcher $contextFetcher,
        AiExceptionAnalyzer $aiAnalyzer,
        Sampler $sampler,
        ReportedExceptions $reported,
        RequestSanitizer $sanitizer
    ) {
        $this->buffer = $buffer;
        $this->contextFetcher = $contextFetcher;
        $this->aiAnalyzer = $aiAnalyzer;
        $this->sampler = $sampler;
        $this->reported = $reported;
        $this->sanitizer = $sanitizer;
    }

    /**
     * Reports an exception to the SqsBuffer for delayed batch shipping.
     *
     * @param Throwable $e
     * @return void
     */
    public function report(Throwable $e): void
    {
        if (! $this->sampler->shouldRecordExceptions()) {
            return;
        }

        if (! $this->reported->claim($e)) {
            return;
        }

        $this->sampler->forceRecord();

        try {
            $request = request();
            $context = $this->contextFetcher->fetchContext($e);
            $aiReport = null;

            if ($this->aiAnalyzer->isEnabled()) {
                $aiReport = $this->aiAnalyzer->generateReport($e, $context);
            }

            $this->buffer->addException([
                'project'     => config('sqs-telemetry.project', 'laravel-app'),
                'class'       => get_class($e),
                'message'     => $this->toUtf8((string) $e->getMessage()),
                'file'        => $e->getFile(),
                'line'        => $e->getLine(),
                'url'         => $request ? $request->fullUrl() : 'Console/Cli',
                'method'      => $request ? $request->method() : null,
                'timestamp'   => now()->toIso8601String(),
                'payload'     => $this->safeGetPayload($request),
                'headers'     => $this->safeGetHeaders($request),
                'ai_resolution_report' => $aiReport,
                // Only capture the first 10 lines of the stack trace to not bloat the SQS message size
                'stack_trace' => $this->toUtf8(implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 10))),
            ]);
        } catch (Throwable $internalException) {
            Log::error('SqsTelemetry: Falha ao reportar exceção pro buffer.', [
                'error' => $internalException->getMessage(),
                'line'  => $internalException->getLine(),
                'file'  => $internalException->getFile(),
            ]);
        }
    }

    /**
     * Coerce a string into valid UTF-8 so `json_encode` cannot reject it.
     *
     * Replaces the old `utf8_encode()` call, which is deprecated as of PHP 8.2
     * and mangles text that was already UTF-8 — the common case here, since an
     * exception message in this application is far more likely to be UTF-8 than
     * Latin-1. Text that is already valid is now left untouched.
     *
     * @param string $value
     * @return string
     */
    protected function toUtf8(string $value): string
    {
        if (! function_exists('mb_check_encoding') || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Safely attempts to get the payload from the request.
     *
     * @param mixed $request
     * @return array|null
     */
    protected function safeGetPayload($request): ?array
    {
        if (!$request) {
            return null;
        }

        try {
            $payload = $request->all();

            return is_array($payload) ? $this->sanitizer->payload($payload) : null;
        } catch (Throwable $e) {
            return ['error' => 'Could not parse payload'];
        }
    }

    /**
     * Safely attempts to get the headers from the request.
     *
     * @param mixed $request
     * @return array|null
     */
    protected function safeGetHeaders($request): ?array
    {
        if (!$request) {
            return null;
        }

        try {
            $headers = $request->headers ? $request->headers->all() : [];

            return is_array($headers) ? $this->sanitizer->headers($headers) : null;
        } catch (Throwable $e) {
            return ['error' => 'Could not parse headers'];
        }
    }
}
