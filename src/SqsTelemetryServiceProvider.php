<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry;

use Illuminate\Support\ServiceProvider;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Services\SqsClientService;

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

        // The Magic Happens Here:
        // By hooking into the terminating event, we ensure that the buffer flush
        // only happens AFTER the HTTP response has already been sent to the user's browser.
        // This makes the I/O to AWS completely non-blocking for the application's response time.
        $this->app->terminating(function () {
            if ($this->app->bound(SqsBuffer::class)) {
                $this->app->make(SqsBuffer::class)->flush();
            }
        });
    }
}
