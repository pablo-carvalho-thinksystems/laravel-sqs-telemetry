<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

use Pablocarvalho\SqsTelemetry\Contracts\ContextResolver;
use Pablocarvalho\SqsTelemetry\Contracts\Transport;
use Throwable;

class SqsBuffer
{
    /**
     * @var array
     */
    protected $messages = [];

    /**
     * Where flushed batches go: straight to SQS, or to a local spool.
     *
     * @var Transport
     */
    protected $sqsClientService;

    /**
     * @var int
     */
    protected $batchSize;

    /**
     * Log entries buffered so far in this request.
     *
     * @var int
     */
    protected $logCount = 0;

    /**
     * Factory that builds the request entry at flush time, if deferred.
     *
     * @var callable|null
     */
    protected $deferredRequest;

    /**
     * Whether this request already produced a request entry.
     *
     * Deliberately not cleared by `flush()`: the shutdown fallback runs after
     * the buffer has already drained, and needs to know that a request was
     * accounted for rather than that the buffer happens to be empty.
     *
     * @var bool
     */
    protected $requestRecorded = false;

    public function __construct(Transport $sqsClientService)
    {
        $this->sqsClientService = $sqsClientService;
        $this->batchSize = (int) config('sqs-telemetry.batch_size', 10);

        // AWS SQS hard limit is 10 messages per batch
        if ($this->batchSize > 10) {
            $this->batchSize = 10;
        }
    }

    /**
     * Adds an HTTP request telemetry entry to the buffer.
     *
     * @param array $data
     * @return void
     */
    public function addRequest(array $data): void
    {
        $this->requestRecorded = true;
        $this->messages[] = array_merge(['type' => 'request'], $data);
    }

    /**
     * Whether a request entry has been produced (or promised) this request.
     *
     * @return bool
     */
    public function hasRecordedRequest(): bool
    {
        return $this->requestRecorded;
    }

    /**
     * Forget the per-request latch, so the next request can record again.
     *
     * @return void
     */
    public function resetRequestState(): void
    {
        $this->requestRecorded = false;
        $this->deferredRequest = null;
        $this->logCount = 0;
    }

    /**
     * Register a factory that builds the request entry when the buffer drains.
     *
     * Only one is kept: a request produces one request entry, and a second call
     * means the first was made against a lifecycle that never completed.
     *
     * @param callable $factory
     * @return void
     */
    public function deferRequest(callable $factory): void
    {
        $this->requestRecorded = true;
        $this->deferredRequest = $factory;
    }

    /**
     * Adds an exception telemetry entry to the buffer.
     *
     * @param array $data
     * @return void
     */
    public function addException(array $data): void
    {
        $this->messages[] = array_merge(['type' => 'exception'], $data);
    }

    /**
     * Adds a command telemetry entry to the buffer.
     *
     * @param array $data
     * @return void
     */
    public function addCommand(array $data): void
    {
        $this->messages[] = array_merge(['type' => 'command'], $data);
    }

    /**
     * Adds a log telemetry entry to the buffer.
     *
     * @param array $data
     * @return void
     */
    public function addLog(array $data): void
    {
        // A single request can emit an unbounded number of log lines (a loop
        // that warns once per row, for instance). Each one would become its own
        // SQS message, so the cap protects the queue, not just this process.
        $max = (int) config('sqs-telemetry.limits.max_logs_per_request', 50);

        if ($max > 0 && $this->logCount >= $max) {
            return;
        }

        $this->logCount++;
        $this->messages[] = array_merge(['type' => 'log'], $data);
    }

    /**
     * Flushes all buffered messages to SQS in batches.
     * This is useful for `app()->terminating()` and prevents memory leaks in Octane.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->materializeDeferredRequest();

        if (empty($this->messages)) {
            TelemetryLog::step('buffer.empty', [
                'hint' => 'nada coletado nesta request (nao amostrada? middleware/shutdown nao capturou?)',
            ]);

            return;
        }

        // Copy and clear the buffer immediately.
        // Useful to ensure Octane doesn't retain data across requests.
        $messagesToSend = $this->messages;
        $this->messages = [];
        $this->logCount = 0;

        TelemetryLog::step('buffer.flush', [
            'count' => count($messagesToSend),
            'transport' => get_class($this->sqsClientService),
        ]);

        $context = array_merge($this->resolveContext(), $this->identity());

        foreach ($messagesToSend as $index => $message) {
            // Host context loses to the message's own keys: a resolver must
            // not be able to overwrite the class of an exception.
            $messagesToSend[$index] = array_merge(
                $context,
                ['message_id' => RequestIdentity::uuid4()],
                $message
            );
        }

        // Split into batches according to configured size (Max 10)
        $batches = array_chunk($messagesToSend, $this->batchSize);

        foreach ($batches as $batch) {
            $this->sqsClientService->sendBatch($batch);
        }
    }

    /**
     * Turn a deferred request factory into a buffered message.
     *
     * Cleared before it runs so a factory that throws cannot be retried on the
     * next flush, and so repeated flushes never duplicate the entry.
     *
     * @return void
     */
    protected function materializeDeferredRequest(): void
    {
        if ($this->deferredRequest === null) {
            return;
        }

        $factory = $this->deferredRequest;
        $this->deferredRequest = null;

        try {
            $data = $factory();

            if (is_array($data)) {
                $this->addRequest($data);
            }
        } catch (Throwable $e) {
            // Runs during teardown; a broken factory costs this one entry.
        }
    }

    /**
     * Fields every consumer needs to count correctly.
     *
     * `request_id` correlates the messages a single request produced;
     * `sampling_rate` is the weight each one carries. Resolved defensively —
     * telemetry without them is still worth shipping.
     *
     * @return array<string, mixed>
     */
    protected function identity(): array
    {
        $identity = [];

        try {
            $identity['request_id'] = app(RequestIdentity::class)->id();
        } catch (Throwable $e) {
            // No correlation id for this flush.
        }

        try {
            $identity['sampling_rate'] = app(Sampler::class)->effectiveRate();
        } catch (Throwable $e) {
            // Consumer will have to assume 1.0.
        }

        return $identity;
    }

    /**
     * Host-supplied fields to merge into every message of this flush.
     *
     * @return array<string, mixed>
     */
    protected function resolveContext(): array
    {
        $resolverClass = config('sqs-telemetry.context.resolver');

        if (empty($resolverClass) || ! is_string($resolverClass)) {
            return [];
        }

        try {
            $resolver = app($resolverClass);

            if (! $resolver instanceof ContextResolver) {
                return [];
            }

            return $resolver->resolve();
        } catch (Throwable $e) {
            // The resolver runs during teardown. A broken one costs us context,
            // never the messages it was supposed to decorate.
            return [];
        }
    }
}
