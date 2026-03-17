<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\ServiceProvider;
use Pablocarvalho\SqsTelemetry\Listeners\SqsCommandListener;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Services\SqsClientService;
use Pablocarvalho\SqsTelemetry\Services\TimelineContext;

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
                // Determine connection name if available
                $connectionName = $event->connectionName ?? 'default';
                
                $timelineContext->addEvent('db_query', $event->sql, $event->time, [
                    'connection' => $connectionName,
                    // Keep bindings out by default to avoid sensitive data leakage, or serialize them safely.
                    // 'bindings' => $event->bindings, 
                ]);
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
