<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqsClientService
{
    /**
     * @var SqsClient|null
     */
    protected $client;

    /**
     * @var string
     */
    protected $queueUrl;

    public function __construct()
    {
        $this->queueUrl = config('sqs-telemetry.queue.url', '');

        if (empty($this->queueUrl) || !config('sqs-telemetry.enabled', true)) {
            $this->client = null;
            return;
        }

        try {
            $config = [
                'version' => 'latest',
                'region'  => config('sqs-telemetry.aws.region', 'us-east-1'),
            ];

            $key = config('sqs-telemetry.aws.key');
            $secret = config('sqs-telemetry.aws.secret');

            if (!empty($key) && !empty($secret)) {
                $config['credentials'] = [
                    'key'    => $key,
                    'secret' => $secret,
                ];
            }

            $this->client = new SqsClient($config);
        } catch (Throwable $e) {
            Log::error('SqsTelemetry: Failed to initialize SQS Client', [
                'exception' => $e->getMessage()
            ]);
            $this->client = null;
        }
    }

    /**
     * Sends a batch of messages to SQS.
     * Max 10 messages per batch (AWS limit).
     *
     * @param array $messages Array of payloads.
     */
    public function sendBatch(array $messages): void
    {
        if (!$this->client || empty($messages)) {
            return;
        }

        try {
            $entries = [];
            foreach ($messages as $index => $message) {
                // SQS requires a unique ID for each message in a batch
                $entries[] = [
                    'Id' => 'msg_' . $index . '_' . uniqid(),
                    'MessageBody' => json_encode($message),
                ];
            }

            $this->client->sendMessageBatch([
                'QueueUrl' => $this->queueUrl,
                'Entries'  => $entries,
            ]);
        } catch (Throwable $e) {
            // Fails gracefully so it doesn't crash the host application during terminating()
            Log::error('SqsTelemetry: Failed to send batch to SQS', [
                'exception' => $e->getMessage(),
                'batch_size' => count($messages),
            ]);
        }
    }
}
