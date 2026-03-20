<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\ServiceProvider;
use Pablocarvalho\SqsTelemetry\Handlers\SqsExceptionHandler;
use Pablocarvalho\SqsTelemetry\Listeners\SqsCommandListener;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Services\SqsClientService;
use Pablocarvalho\SqsTelemetry\Services\TimelineContext;
use Throwable;

class SqsTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/sqs-telemetry.php', 'sqs-telemetry'
        );

        // Bind the SQS Client Wrapper
        $this->app->singleton(SqsClientService::class, function ($app) {
            return new SqsClientService();
        });

        // Bind the Memory Buffer as a Singleton so the Middleware and ExceptionHandler share the same instance
        $this->app->singleton(SqsBuffer::class, function ($app) {
            return new SqsBuffer($app->make(SqsClientService::class));
        });

        // Bind TimelineContext as a Singleton
        $this->app->singleton(TimelineContext::class, function ($app) {
            return new TimelineContext();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish configuration for the host application
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sqs-telemetry.php' => config_path('sqs-telemetry.php'),
            ], 'sqs-telemetry-config');
        }

        // Register Timeline Listeners
        $this->registerTimelineListeners();

        // Register Command Listeners
        $this->registerCommandListeners();

        // The Magic Happens Here:
        // By hooking into the terminating event, we ensure that the buffer flush
        // only happens AFTER the HTTP response has already been sent to the user's browser.
        // This makes the I/O to AWS completely non-blocking for the application's response time.
        $this->app->terminating(function () {
            if ($this->app->bound(SqsBuffer::class)) {
                $this->app->make(SqsBuffer::class)->flush();
            }

            if ($this->app->bound(TimelineContext::class)) {
                $this->app->make(TimelineContext::class)->flush();
            }
        });
    }

    /**
     * Register listeners for timeline events if enabled in config.
     *
     * @return void
     */
    protected function registerTimelineListeners(): void
    {
        $timelineContext = $this->app->make(TimelineContext::class);

        // Database Queries
        if (config('sqs-telemetry.timeline.db', true)) {
            $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use ($timelineContext) {
                // Determine connection name and database if available
                $connectionName = $event->connectionName ?? 'default';
                $database = $event->connection->getDatabaseName();

                $metadata = [
                    'connection' => $connectionName,
                    'database'   => $database,
                ];

                if (config('sqs-telemetry.timeline.db_bindings', true)) {
                    $metadata['bindings'] = $this->sanitizeBindings($event->sql, $event->bindings);
                }

                if (config('sqs-telemetry.timeline.db_source_location', true)) {
                    try {
                        $source = $this->resolveQuerySource();
                        if ($source) {
                            $metadata['source_file'] = $source['file'];
                            $metadata['source_line'] = $source['line'];
                        }
                    } catch (\Throwable $e) {
                        // Silently fail to avoid breaking query capture
                    }
                }

                $timelineContext->addEvent('db_query', $event->sql, $event->time, $metadata);
            });
        }

        // HTTP Client Requests
        if (config('sqs-telemetry.timeline.http', true)) {
            if (class_exists(ResponseReceived::class)) {
                $this->app['events']->listen(ResponseReceived::class, function (ResponseReceived $event) use ($timelineContext) {
                    $url = (string) $event->request->url();
                    $method = $event->request->method();
                    $status = $event->response->status();
                    
                    $durationMs = 0.0;
                    if (method_exists($event->response, 'handlerStats') && $event->response->handlerStats() && isset($event->response->handlerStats()['total_time'])) {
                        $durationMs = $event->response->handlerStats()['total_time'] * 1000;
                    }

                    $timelineContext->addEvent('http_request', "$method $url", $durationMs, [
                        'status' => $status
                    ]);
                });
            }

            if (class_exists(ConnectionFailed::class)) {
                $this->app['events']->listen(ConnectionFailed::class, function (ConnectionFailed $event) use ($timelineContext) {
                    $url = (string) $event->request->url();
                    $method = $event->request->method();
                    
                    $timelineContext->addEvent('http_request_failed', "$method $url", 0.0, [
                        'error' => 'Connection Failed'
                    ]);
                });
            }
        }

        // Cache Operations (Basic Listeners)
        if (config('sqs-telemetry.timeline.cache', true)) {
            $this->app['events']->listen(\Illuminate\Cache\Events\CacheHit::class, function ($event) use ($timelineContext) {
                $timelineContext->addEvent('cache_hit', "Cache hit: {$event->key}", 0.0);
            });
            $this->app['events']->listen(\Illuminate\Cache\Events\CacheMissed::class, function ($event) use ($timelineContext) {
                $timelineContext->addEvent('cache_miss', "Cache miss: {$event->key}", 0.0);
            });
            $this->app['events']->listen(\Illuminate\Cache\Events\KeyWritten::class, function ($event) use ($timelineContext) {
                $timelineContext->addEvent('cache_write', "Cache write: {$event->key}", 0.0);
            });
            $this->app['events']->listen(\Illuminate\Cache\Events\KeyForgotten::class, function ($event) use ($timelineContext) {
                $timelineContext->addEvent('cache_forget', "Cache forget: {$event->key}", 0.0);
            });
        }

        // Logs & Exceptions (captured from MessageLogged event)
        $captureExceptions = config('sqs-telemetry.timeline.exceptions', true);
        $captureLogs = config('sqs-telemetry.timeline.logs', true);

        if ($captureExceptions || $captureLogs) {
            $buffer = $this->app->make(SqsBuffer::class);
            $reportedExceptions = [];

            $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event) use ($timelineContext, $buffer, &$reportedExceptions, $captureExceptions, $captureLogs) {
                // Skip internal SQS telemetry logs to avoid infinite loops
                if (isset($event->context['__sqs_telemetry'])) {
                    return;
                }

                $hasException = isset($event->context['exception']) && ($event->context['exception'] instanceof Throwable);

                // Handle exception logs
                if ($hasException && $captureExceptions) {
                    $exception = $event->context['exception'];
                    $hash = spl_object_hash($exception);

                    if (!isset($reportedExceptions[$hash])) {
                        $reportedExceptions[$hash] = true;

                        $timelineContext->addEvent('exception', get_class($exception) . ': ' . $exception->getMessage(), 0.0, [
                            'class'   => get_class($exception),
                            'message' => $exception->getMessage(),
                            'file'    => $exception->getFile(),
                            'line'    => $exception->getLine(),
                            'handled' => true,
                        ]);

                        try {
                            $request = request();

                            $buffer->addException([
                                'project'     => config('sqs-telemetry.project', 'laravel-app'),
                                'class'       => get_class($exception),
                                'message'     => $exception->getMessage(),
                                'file'        => $exception->getFile(),
                                'line'        => $exception->getLine(),
                                'url'         => $request ? $request->fullUrl() : 'Console/Cli',
                                'method'      => $request ? $request->method() : null,
                                'timestamp'   => now()->toIso8601String(),
                                'handled'     => true,
                                'log_level'   => $event->level,
                                'stack_trace' => implode("\n", array_slice(explode("\n", $exception->getTraceAsString()), 0, 10)),
                            ]);
                        } catch (Throwable $e) {
                            // Silently fail to avoid infinite loops
                        }
                    }

                    return; // Exception already handled, skip general log capture
                }

                // Handle general logs (non-exception)
                if ($captureLogs && !$hasException) {
                    // Add to timeline
                    $timelineContext->addEvent('log', "[{$event->level}] {$event->message}", 0.0, [
                        'level'   => $event->level,
                        'context' => $this->sanitizeLogContext($event->context),
                    ]);

                    // Send as standalone log entry
                    try {
                        $request = request();

                        $buffer->addLog([
                            'project'   => config('sqs-telemetry.project', 'laravel-app'),
                            'level'     => $event->level,
                            'message'   => $event->message,
                            'context'   => $this->sanitizeLogContext($event->context),
                            'url'       => $request ? $request->fullUrl() : 'Console/Cli',
                            'method'    => $request ? $request->method() : null,
                            'timestamp' => now()->toIso8601String(),
                        ]);
                    } catch (Throwable $e) {
                        // Silently fail to avoid infinite loops
                    }
                }
            });
        }
    }

    /**
     * Sanitize query bindings by redacting values for sensitive columns.
     *
     * @param string $sql
     * @param array $bindings
     * @return array
     */
    protected function sanitizeBindings(string $sql, array $bindings): array
    {
        $sensitivePattern = '/password|secret|token|api_key|cpf|cnpj/i';

        // Try to extract column names from INSERT statements to match with bindings
        if (preg_match('/\(([^)]+)\)\s*values/i', $sql, $matches)) {
            $columns = array_map('trim', explode(',', str_replace('"', '', $matches[1])));

            foreach ($bindings as $index => $value) {
                if (isset($columns[$index]) && preg_match($sensitivePattern, $columns[$index])) {
                    $bindings[$index] = '[REDACTED]';
                }
            }
        }

        // Also check for UPDATE SET assignments: SET column = ?
        if (preg_match_all('/([\w"]+)\s*=\s*\?/i', $sql, $matches)) {
            $columns = array_map(function ($col) {
                return trim(str_replace('"', '', $col));
            }, $matches[1]);

            foreach ($columns as $index => $column) {
                if (isset($bindings[$index]) && preg_match($sensitivePattern, $column)) {
                    $bindings[$index] = '[REDACTED]';
                }
            }
        }

        return $bindings;
    }

    /**
     * Sanitize log context by removing non-serializable values and redacting sensitive keys.
     *
     * @param array $context
     * @return array
     */
    protected function sanitizeLogContext(array $context): array
    {
        $sensitiveKeys = ['password', 'secret', 'token', 'api_key', 'cpf', 'cnpj', 'authorization'];
        $sanitized = [];

        foreach ($context as $key => $value) {
            // Skip internal markers and exception objects
            if ($key === '__sqs_telemetry' || $key === 'exception') {
                continue;
            }

            // Redact sensitive keys
            if (preg_match('/password|secret|token|api_key|cpf|cnpj|authorization/i', (string) $key)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            // Handle non-serializable values
            if (is_object($value)) {
                $sanitized[$key] = get_class($value);
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeLogContext($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Resolve the application-level source file and line that triggered a database query.
     * Uses debug_backtrace to find the first frame within the application code.
     *
     * @return array|null Returns ['file' => relative_path, 'line' => int] or null.
     */
    protected function resolveQuerySource(): ?array
    {
        $basePath = base_path();
        $vendorPath = base_path('vendor') . DIRECTORY_SEPARATOR;

        // Patterns to skip: vendor dir, Laravel framework internals, and this package
        $skipPatterns = [
            $vendorPath,
            DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
        ];

        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);

        foreach ($frames as $frame) {
            if (!isset($frame['file']) || !isset($frame['line'])) {
                continue;
            }

            $file = $frame['file'];

            // Skip files matching any skip pattern
            $skip = false;
            foreach ($skipPatterns as $pattern) {
                if (strpos($file, $pattern) !== false) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            // Skip PHP internal files (eval'd code, etc.)
            if (strpos($file, 'eval()') !== false || strpos($file, 'php://') !== false) {
                continue;
            }

            // Try to make the path relative to base_path
            if (strpos($file, $basePath) === 0) {
                $relativePath = ltrim(str_replace($basePath, '', $file), DIRECTORY_SEPARATOR);
            } else {
                // If not inside base_path, use the basename with parent dir for context
                $relativePath = basename(dirname($file)) . DIRECTORY_SEPARATOR . basename($file);
            }

            return [
                'file' => $relativePath,
                'line' => $frame['line'],
            ];
        }

        return null;
    }

    /**
     * Register listeners for Artisan command events if enabled in config.
     *
     * @return void
     */
    protected function registerCommandListeners(): void
    {
        if (!config('sqs-telemetry.timeline.commands', true)) {
            return;
        }

        $listener = new SqsCommandListener(
            $this->app->make(SqsBuffer::class),
            $this->app->make(TimelineContext::class)
        );

        $this->app['events']->listen(CommandStarting::class, [$listener, 'handleStarting']);
        $this->app['events']->listen(CommandFinished::class, [$listener, 'handleFinished']);
    }
}
