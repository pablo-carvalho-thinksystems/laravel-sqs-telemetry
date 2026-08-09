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

    /**
     * @var bool
     */
    protected $clientResolved = false;

    public function __construct()
    {
        $this->queueUrl = config('sqs-telemetry.queue.url', '');
    }

    /**
     * Build the SQS client on first use instead of on construction.
     *
     * The AWS SDK is a heavy autoload: instantiating a client pulls in a large
     * object graph. Doing that while merely *registering* telemetry means every
     * request pays for it, including the ones that never emit a single message.
     *
     * That cost is negligible on a long-lived worker, but not on hosts that boot
     * the whole framework per request. Resolving lazily keeps requests that have
     * nothing to report free of the SDK entirely.
     *
     * @return SqsClient|null
     */
    protected function client()
    {
        if ($this->clientResolved) {
            return $this->client;
        }

        $this->clientResolved = true;

        if (empty($this->queueUrl) || !config('sqs-telemetry.enabled', true)) {
            $this->client = null;
            return null;
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

        return $this->client;
    }

    /**
     * Sends a batch of messages to SQS.
     * Max 10 messages per batch (AWS limit).
     *
     * @param array $messages Array of payloads.
     */
    public function sendBatch(array $messages): void
    {
        // Check the payload first: an empty batch must not be a reason to build
        // the AWS client (see client()).
        if (empty($messages)) {
            return;
        }

        $client = $this->client();

        if (!$client) {
            return;
        }

        try {
            // SQS caps a SendMessageBatch call at 256 KB across every entry, so
            // a batch is bounded by total bytes as well as by message count. A
            // single oversized timeline would otherwise fail the whole call and
            // take nine healthy messages down with it.
            $maxBytes = (int) config('sqs-telemetry.limits.max_message_bytes', 240000);

            $entries = [];
            $bytes = 0;

            foreach ($messages as $index => $message) {
                $body = $this->encodeWithinLimit($message, $maxBytes);

                if ($body === null) {
                    continue;
                }

                $size = strlen($body);

                if ($entries !== [] && ($bytes + $size) > $maxBytes) {
                    $this->dispatch($client, $entries);
                    $entries = [];
                    $bytes = 0;
                }

                // SQS requires a unique ID for each message in a batch
                $entries[] = [
                    'Id' => 'msg_' . $index . '_' . uniqid(),
                    'MessageBody' => $body,
                ];
                $bytes += $size;
            }

            if ($entries !== []) {
                $this->dispatch($client, $entries);
            }
        } catch (Throwable $e) {
            // Fails gracefully so it doesn't crash the host application during terminating()
            Log::error('SqsTelemetry: Failed to send batch to SQS', [
                'exception' => $e->getMessage(),
                'batch_size' => count($messages),
                '__sqs_telemetry' => true,
            ]);
        }
    }

    /**
     * Send one batch of already-encoded entries.
     *
     * @param SqsClient $client
     * @param array $entries
     * @return void
     */
    protected function dispatch($client, array $entries): void
    {
        $client->sendMessageBatch([
            'QueueUrl' => $this->queueUrl,
            'Entries'  => $entries,
        ]);
    }

    /**
     * Encode a message, shedding its bulkiest fields until it fits.
     *
     * The timeline goes first because it is both the largest field and the most
     * expendable one — the request or exception it describes is still worth
     * shipping without it.
     *
     * @param array $message
     * @param int $maxBytes
     * @return string|null Null when the message cannot be encoded at all.
     */
    protected function encodeWithinLimit(array $message, int $maxBytes): ?string
    {
        $body = json_encode($message);

        if ($body === false) {
            return null;
        }

        if ($maxBytes <= 0 || strlen($body) <= $maxBytes) {
            return $body;
        }

        foreach (['timeline', 'payload', 'headers', 'stack_trace', 'ai_resolution_report'] as $field) {
            if (! array_key_exists($field, $message)) {
                continue;
            }

            $message[$field] = null;
            $message['truncated_fields'] = isset($message['truncated_fields'])
                ? $message['truncated_fields'] . ',' . $field
                : $field;

            $body = json_encode($message);

            if ($body === false) {
                return null;
            }

            if (strlen($body) <= $maxBytes) {
                return $body;
            }
        }

        return strlen($body) <= $maxBytes ? $body : null;
    }
}
