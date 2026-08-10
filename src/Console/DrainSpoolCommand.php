<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Pablocarvalho\SqsTelemetry\Services\RedisSpoolTransport;
use Pablocarvalho\SqsTelemetry\Services\SqsClientService;
use Throwable;

/**
 * Moves the Redis spool to SQS, out of the request path.
 *
 * Run it from cron every minute, or as a long-lived process with `--daemon`.
 * Nothing in a web request waits on this.
 */
class DrainSpoolCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sqs-telemetry:drain
        {--limit=1000 : Maximum messages to ship in one pass (0 = until empty)}
        {--daemon : Keep draining until interrupted}
        {--sleep=1 : Seconds to wait when the spool is empty, in daemon mode}';

    /**
     * @var string
     */
    protected $description = 'Ship spooled telemetry from Redis to SQS';

    /**
     * Entries popped but unreadable.
     *
     * @var int
     */
    protected $discarded = 0;

    public function handle(RedisSpoolTransport $spool, SqsClientService $sqs): int
    {
        $key = $spool->key();
        $connection = Redis::connection($spool->connectionName());
        $batchSize = min(10, max(1, (int) config('sqs-telemetry.batch_size', 10)));
        $limit = (int) $this->option('limit');
        $daemon = (bool) $this->option('daemon');
        $sleep = max(1, (int) $this->option('sleep'));

        $shipped = 0;

        do {
            $batch = $this->pop($connection, $key, $batchSize);

            if ($batch === []) {
                if (! $daemon) {
                    break;
                }

                sleep($sleep);

                continue;
            }

            try {
                // SQS can reject individual entries while answering 200, so
                // only what it refused goes back on the spool. The rest is
                // already in the queue and must not be sent twice.
                $rejected = $sqs->send($batch);
                $shipped += count($batch) - count($rejected);

                if ($rejected !== []) {
                    $this->requeue($connection, $key, $rejected);
                    $this->error(sprintf(
                        'O SQS recusou %d de %d mensagens; devolvidas ao spool.',
                        count($rejected),
                        count($batch)
                    ));

                    return self::FAILURE;
                }
            } catch (Throwable $e) {
                // Put the batch back so a transient AWS failure does not lose
                // telemetry, then stop: retrying immediately would just spin.
                $this->requeue($connection, $key, $batch);
                $this->error('Falha ao enviar para o SQS: ' . $e->getMessage());

                return self::FAILURE;
            }

            if (! $daemon && $limit > 0 && $shipped >= $limit) {
                break;
            }
        } while (true);

        $this->info("Telemetria enviada: {$shipped} mensagem(ns).");

        if ($this->discarded > 0) {
            $this->warn("Descartadas por conteúdo ilegível: {$this->discarded}.");
        }

        return self::SUCCESS;
    }

    /**
     * @param mixed $connection
     * @param string $key
     * @param int $count
     * @return array<int, array<string, mixed>>
     */
    protected function pop($connection, string $key, int $count): array
    {
        $messages = [];

        for ($i = 0; $i < $count; $i++) {
            $raw = $connection->lpop($key);

            if ($raw === null || $raw === false) {
                break;
            }

            $decoded = json_decode((string) $raw, true);

            if (is_array($decoded)) {
                $messages[] = $decoded;

                continue;
            }

            // An entry that will not decode is already lost — it was popped.
            // Say so, rather than reporting a clean run that shipped nothing.
            $this->discarded++;
        }

        return $messages;
    }

    /**
     * @param mixed $connection
     * @param string $key
     * @param array<int, array<string, mixed>> $batch
     * @return void
     */
    protected function requeue($connection, string $key, array $batch): void
    {
        try {
            $payloads = [];

            foreach (array_reverse($batch) as $message) {
                $encoded = json_encode($message);

                if ($encoded !== false) {
                    $payloads[] = $encoded;
                }
            }

            if ($payloads !== []) {
                $connection->lpush($key, ...$payloads);
            }
        } catch (Throwable $e) {
            // The batch is lost. Nothing further to try.
        }
    }
}
