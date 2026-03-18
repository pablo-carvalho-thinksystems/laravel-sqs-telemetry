<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

class SqsBuffer
{
    /**
     * @var array
     */
    protected $messages = [];

    /**
     * @var SqsClientService
     */
    protected $sqsClientService;

    /**
     * @var int
     */
    protected $batchSize;

    public function __construct(SqsClientService $sqsClientService)
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
        $this->messages[] = array_merge(['type' => 'request'], $data);
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
        if (empty($this->messages)) {
            return;
        }

        // Copy and clear the buffer immediately.
        // Useful to ensure Octane doesn't retain data across requests.
        $messagesToSend = $this->messages;
        $this->messages = [];

        // Split into batches according to configured size (Max 10)
        $batches = array_chunk($messagesToSend, $this->batchSize);

        foreach ($batches as $batch) {
            $this->sqsClientService->sendBatch($batch);
        }
    }
}
