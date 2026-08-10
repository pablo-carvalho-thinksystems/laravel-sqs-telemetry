<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Pablocarvalho\SqsTelemetry\Contracts\Transport;
use Throwable;

/**
 * Parks telemetry in Redis so the request never waits on AWS.
 *
 * Shipping straight to SQS is non-blocking for the *user* — the response is
 * already flushed — but not for the *worker*: the PHP thread stays occupied
 * for the whole round trip, which was measured at ~550ms to a remote region.
 * On a host with a fixed thread pool that is the throughput ceiling, and under
 * load the telemetry becomes the bottleneck it was meant to measure.
 *
 * A local Redis push costs well under a millisecond, so the request is done
 * with telemetry almost immediately. `sqs-telemetry:drain` moves the spool to
 * SQS out of band, where latency costs nothing that a user is waiting on.
 */
class RedisSpoolTransport implements Transport
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @return void
     */
    public function sendBatch(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        try {
            $payloads = [];

            foreach ($messages as $message) {
                $encoded = json_encode($message);

                if ($encoded !== false) {
                    $payloads[] = $encoded;
                }
            }

            if ($payloads === []) {
                return;
            }

            $connection = Redis::connection($this->connectionName());
            $key = $this->key();

            // Spread, never the array itself: the client takes values
            // variadically, and passing the array pushes a single unusable
            // element that only fails later, in the drainer.
            $length = $connection->rpush($key, ...$payloads);

            // Bound the spool so a drainer that has died cannot turn telemetry
            // into an out-of-memory incident. Keeps the newest entries.
            $max = (int) config('sqs-telemetry.spool.max_length', 100000);

            if ($max > 0 && is_int($length) && $length > $max) {
                $connection->ltrim($key, -$max, -1);

                // A full spool means nothing is draining it. Saying so beats
                // discovering later that telemetry has a hole in it.
                Log::warning('SqsTelemetry: spool cheio, descartando as mais antigas', [
                    'discarded' => $length - $max,
                    'max_length' => $max,
                    'hint' => 'sqs-telemetry:drain não está rodando?',
                    '__sqs_telemetry' => true,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('SqsTelemetry: Failed to spool batch to Redis', [
                'exception' => $e->getMessage(),
                'batch_size' => count($messages),
                '__sqs_telemetry' => true,
            ]);
        }
    }

    /**
     * Redis list holding the spool.
     *
     * @return string
     */
    public function key(): string
    {
        $key = config('sqs-telemetry.spool.key', 'sqs-telemetry:spool');

        return is_string($key) && $key !== '' ? $key : 'sqs-telemetry:spool';
    }

    /**
     * @return string|null
     */
    public function connectionName(): ?string
    {
        $connection = config('sqs-telemetry.spool.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
