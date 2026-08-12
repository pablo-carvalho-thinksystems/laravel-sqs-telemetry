<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Log;
use Pablocarvalho\SqsTelemetry\Contracts\Transport;
use Throwable;

class SqsClientService implements Transport
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

            // Ponto cego classico: sem URL de fila ou desabilitado, o envio vira
            // no-op — send() devolve [] e o drainer marca tudo como enviado,
            // esvaziando o spool sem que nada chegue na fila.
            TelemetryLog::step('sqs.client.skip', [
                'reason' => empty($this->queueUrl) ? 'queue.url vazia' : 'enabled=false',
                'queue_url_set' => ! empty($this->queueUrl),
                'enabled' => (bool) config('sqs-telemetry.enabled', true),
            ], 'warning');

            return null;
        }

        try {
            $config = [
                'version' => 'latest',
                'region'  => config('sqs-telemetry.aws.region', 'us-east-1'),
            ];

            // The SDK resolves the endpoint from the region, not from the queue
            // URL, so an SQS-compatible server (ElasticMQ, LocalStack) is only
            // reachable by overriding it explicitly.
            $endpoint = config('sqs-telemetry.aws.endpoint');

            if (! empty($endpoint)) {
                $config['endpoint'] = $endpoint;
                $config['use_path_style_endpoint'] = true;
            }

            $key = config('sqs-telemetry.aws.key');
            $secret = config('sqs-telemetry.aws.secret');

            if (!empty($key) && !empty($secret)) {
                $config['credentials'] = [
                    'key'    => $key,
                    'secret' => $secret,
                ];
            }

            $this->client = new SqsClient($config);

            TelemetryLog::step('sqs.client.ready', [
                'region' => $config['region'],
                'endpoint' => $config['endpoint'] ?? '(aws default)',
                'has_credentials' => isset($config['credentials']),
                'queue_url' => $this->queueUrl,
            ]);
        } catch (Throwable $e) {
            Log::error('SqsTelemetry: Failed to initialize SQS Client', [
                'exception' => $e->getMessage(),
                '__sqs_telemetry' => true,
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
        $failed = $this->send($messages);

        if ($failed !== []) {
            Log::error('SqsTelemetry: SQS rejected part of the batch', [
                'rejected' => count($failed),
                'of' => count($messages),
                '__sqs_telemetry' => true,
            ]);
        }
    }

    /**
     * Send messages, reporting the ones SQS would not take.
     *
     * `SendMessageBatch` answers 200 with a `Failed` list when individual
     * entries are rejected — throttling, or an entry the service dislikes. The
     * call does not throw, so ignoring the response loses those messages
     * without a trace. Callers that can retry (the spool drainer) get them
     * back; callers that cannot at least get a log line.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>> Messages that were not accepted.
     */
    public function send(array $messages): array
    {
        // Check the payload first: an empty batch must not be a reason to build
        // the AWS client (see client()).
        if (empty($messages)) {
            return [];
        }

        TelemetryLog::step('sqs.send.begin', [
            'count' => count($messages),
            'queue_url' => $this->queueUrl,
        ]);

        $client = $this->client();

        if (!$client) {
            TelemetryLog::step('sqs.send.no_client', [
                'discarded' => count($messages),
                'hint' => 'sem cliente SQS: mensagens NAO enviadas (queue.url vazia ou enabled=false)',
            ], 'warning');

            return [];
        }

        $rejected = [];

        try {
            // SQS caps a SendMessageBatch call at 256 KB across every entry, so
            // a batch is bounded by total bytes as well as by message count. A
            // single oversized timeline would otherwise fail the whole call and
            // take nine healthy messages down with it.
            $maxBytes = (int) config('sqs-telemetry.limits.max_message_bytes', 240000);

            $entries = [];
            $bytes = 0;
            $byEntryId = [];

            foreach ($messages as $index => $message) {
                $body = $this->encodeWithinLimit($message, $maxBytes);

                if ($body === null) {
                    // Could not be encoded at any size; retrying will not help.
                    $rejected[] = $message;

                    continue;
                }

                $size = strlen($body);

                if ($entries !== [] && ($bytes + $size) > $maxBytes) {
                    $rejected = array_merge($rejected, $this->dispatch($client, $entries, $byEntryId));
                    $entries = [];
                    $byEntryId = [];
                    $bytes = 0;
                }

                // SQS requires a unique ID for each message in a batch
                $entryId = 'msg_' . $index . '_' . uniqid();
                $entries[] = [
                    'Id' => $entryId,
                    'MessageBody' => $body,
                ];
                $byEntryId[$entryId] = $message;
                $bytes += $size;
            }

            if ($entries !== []) {
                $rejected = array_merge($rejected, $this->dispatch($client, $entries, $byEntryId));
            }
        } catch (Throwable $e) {
            // Fails gracefully so it doesn't crash the host application during terminating()
            Log::error('SqsTelemetry: Failed to send batch to SQS', [
                'exception' => $e->getMessage(),
                'batch_size' => count($messages),
                '__sqs_telemetry' => true,
            ]);

            // The whole call failed, so nothing in it arrived.
            return $messages;
        }

        TelemetryLog::step('sqs.send.done', [
            'sent' => count($messages) - count($rejected),
            'rejected' => count($rejected),
        ]);

        return $rejected;
    }

    /**
     * Send one batch of already-encoded entries.
     *
     * @param SqsClient $client
     * @param array $entries
     * @return void
     */
    protected function dispatch($client, array $entries, array $byEntryId = []): array
    {
        $result = $client->sendMessageBatch([
            'QueueUrl' => $this->queueUrl,
            'Entries'  => $entries,
        ]);

        $failed = $result['Failed'] ?? [];

        if (! is_array($failed) || $failed === []) {
            return [];
        }

        $rejected = [];

        foreach ($failed as $failure) {
            // O motivo real de o SQS recusar uma entrada (throttling, payload,
            // permissao). Sem isto, uma rejeicao parcial some sem rastro.
            TelemetryLog::step('sqs.batch.rejected', [
                'code' => $failure['Code'] ?? null,
                'message' => $failure['Message'] ?? null,
                'sender_fault' => $failure['SenderFault'] ?? null,
            ], 'warning');

            $id = $failure['Id'] ?? null;

            if ($id !== null && isset($byEntryId[$id])) {
                $rejected[] = $byEntryId[$id];
            }
        }

        return $rejected;
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
