<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SQS Telemetry Enable/Disable
    |--------------------------------------------------------------------------
    |
    | This value determines if the telemetry should actually be sent to SQS.
    | Useful for local development where you might want to disable it.
    |
    */
    'enabled' => env('SQS_TELEMETRY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Project Identifier
    |--------------------------------------------------------------------------
    |
    | Identifier for the project sending telemetry data.
    | Useful for filtering or identifying data in SQS.
    |
    */
    'project' => env('SQS_TELEMETRY_PROJECT', env('APP_NAME', 'laravel-app')),

    /*
    |--------------------------------------------------------------------------
    | AWS Credentials & Region
    |--------------------------------------------------------------------------
    |
    | Here you may configure your AWS credentials. By default, it uses the
    | standard AWS environment variables, but you can override them here.
    |
    | Set `endpoint` to point at an SQS-compatible server (ElasticMQ,
    | LocalStack). The SDK resolves the endpoint from the region and ignores the
    | queue URL's host, so local development needs this override.
    |
    */
    'aws' => [
        'key'      => env('AWS_ACCESS_KEY_ID'),
        'secret'   => env('AWS_SECRET_ACCESS_KEY'),
        'region'   => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'endpoint' => env('SQS_TELEMETRY_ENDPOINT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SQS Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Define your queue URL and the maximum batch size.
    | Note: AWS SQS allows a maximum of 10 messages per batch.
    |
    */
    'queue' => [
        'url' => env('SQS_TELEMETRY_QUEUE_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    |
    | Max 10. The SDK will bundle requests/exceptions into batches of this size.
    |
    */
    'batch_size' => env('SQS_TELEMETRY_BATCH_SIZE', 10),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | `sqs` ships from inside the request. The response is already flushed by
    | then, so the user waits on nothing — but the PHP worker does, for the
    | whole round trip to AWS. On a host with a fixed thread pool that is a hard
    | throughput ceiling: ~550ms per flush against a remote region across eight
    | threads caps the application at roughly fifteen requests per second.
    |
    | `redis` writes the batch to a local list instead, which costs well under a
    | millisecond, and `sqs-telemetry:drain` moves it to SQS out of band. Use it
    | anywhere throughput matters.
    |
    */
    'transport' => env('SQS_TELEMETRY_TRANSPORT', 'sqs'),

    'spool' => [
        'connection' => env('SQS_TELEMETRY_SPOOL_CONNECTION', 'default'),
        'key' => env('SQS_TELEMETRY_SPOOL_KEY', 'sqs-telemetry:spool'),
        // Bounds the list so a dead drainer cannot exhaust Redis. Oldest go
        // first, on the grounds that a backlog nobody shipped is stale anyway.
        'max_length' => env('SQS_TELEMETRY_SPOOL_MAX_LENGTH', 100000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Flush On Shutdown
    |--------------------------------------------------------------------------
    |
    | `terminating()` is fired by Laravel's own HTTP/Console kernels. Hosts that
    | embed the container without owning the request lifecycle — Acorn inside
    | WordPress being the canonical case — never fire it, and the buffer would be
    | discarded. Keeping this on registers a PHP shutdown function as a fallback.
    |
    */
    'flush_on_shutdown' => env('SQS_TELEMETRY_FLUSH_ON_SHUTDOWN', true),

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | Fraction of requests (0.0 to 1.0) that record telemetry. The decision is
    | taken once per request, before any listener does work, so an unsampled
    | request pays almost nothing — no timeline, no buffer, no SQS call.
    |
    | Exceptions ignore this setting when `always_record_exceptions` is on: an
    | error is never worth dropping, however aggressive the sampling.
    |
    */
    'sampling' => [
        'rate'                     => env('SQS_TELEMETRY_SAMPLING_RATE', 1.0),
        'always_record_exceptions' => env('SQS_TELEMETRY_ALWAYS_RECORD_EXCEPTIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload Limits
    |--------------------------------------------------------------------------
    |
    | SQS caps a SendMessageBatch call at 256 KB across every entry, so an
    | oversized timeline does not merely bloat the payload — it fails the whole
    | batch. These limits keep a single pathological request from costing every
    | other message batched alongside it.
    |
    | `max_timeline_events` matters most on query-heavy stacks (a WordPress page
    | load can issue hundreds of queries in one request).
    |
    */
    'limits' => [
        'max_timeline_events'   => env('SQS_TELEMETRY_MAX_TIMELINE_EVENTS', 200),
        'max_logs_per_request'  => env('SQS_TELEMETRY_MAX_LOGS_PER_REQUEST', 50),
        'max_message_bytes'     => env('SQS_TELEMETRY_MAX_MESSAGE_BYTES', 240000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture Behaviour
    |--------------------------------------------------------------------------
    |
    | Turn this on where the response is produced *after* Laravel's middleware
    | stack has unwound. Acorn serving a WordPress page is the case it exists
    | for: the kernel runs on `after_setup_theme` and WordPress renders after
    | it, so a middleware measuring on the way out times the kernel pass and
    | reports an empty timeline with the wrong status code.
    |
    | With it on, duration, status and timeline are read when the buffer drains
    | during `terminating()` — after the real response exists.
    |
    | `fallback_to_shutdown` covers the other half of the same problem: hosts
    | that bootstrap the framework and then hand the request back without
    | routing it. Acorn does that for paths matching `admin_url()`,
    | `rest_url()`, the login URLs or ending in `.php`, so listeners collect but
    | no middleware ever runs and the request goes unrecorded. With this on, the
    | request is reconstructed from PHP's superglobals at shutdown and marked
    | `capture_source: shutdown`.
    |
    | Which paths actually fall under that rule depends on how the server builds
    | `SCRIPT_NAME`/`PATH_INFO` — read `capture_source` on real traffic rather
    | than assuming.
    |
    | `finish_request_before_flush` releases the response to the client before
    | the SQS call, and applies only to that fallback path — when the kernel
    | handles the request the host has already released it. Output written
    | after this point is discarded, which is why it is opt-in.
    |
    */
    'capture' => [
        'defer_request_to_flush' => env('SQS_TELEMETRY_DEFER_REQUEST', false),
        'fallback_to_shutdown' => env('SQS_TELEMETRY_FALLBACK_TO_SHUTDOWN', false),
        'finish_request_before_flush' => env('SQS_TELEMETRY_FINISH_REQUEST', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Resolver
    |--------------------------------------------------------------------------
    |
    | Class name of a resolver that adds host-specific fields to every message
    | (tenant, site, release, pod...). It must implement the package's
    | `Contracts\ContextResolver` interface and is resolved from the container.
    |
    | A class name rather than a closure so the config stays cacheable.
    |
    */
    'context' => [
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Exception Analyzer
    |--------------------------------------------------------------------------
    |
    | When enabled, the package will send the exception trace and code context
    | to an AI provider (default OpenAI) to generate a resolution report.
    | Note: This happens synchronously and may add delay to the response.
    |
    */
    'ai' => [
        'enabled'  => env('SQS_TELEMETRY_AI_ENABLED', false),
        'provider' => env('SQS_TELEMETRY_AI_PROVIDER', 'openai'),
        'model'    => env('SQS_TELEMETRY_AI_MODEL', 'gpt-4o-mini'),
        'api_key'  => env('SQS_TELEMETRY_AI_API_KEY', ''),
        'api_url'  => env('SQS_TELEMETRY_AI_API_URL', 'https://api.openai.com/v1/chat/completions'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeline Telemetry
    |--------------------------------------------------------------------------
    |
    | Configuration for capturing the request timeline (execution times).
    | You can enable or disable specific types of timeline events.
    |
    | `db_source_location` walks a 50-frame backtrace for every query executed.
    | That is affordable while debugging a handful of queries and ruinous on a
    | stack that issues hundreds per request, so it stays off unless asked for.
    |
    */
    'timeline' => [
        'db'          => env('SQS_TELEMETRY_TIMELINE_DB', true),
        'db_bindings' => true,
        'db_source_location' => env('SQS_TELEMETRY_TIMELINE_DB_SOURCE', false),
        'http'        => env('SQS_TELEMETRY_TIMELINE_HTTP', true),
        'cache'       => env('SQS_TELEMETRY_TIMELINE_CACHE', true),
        'commands'    => env('SQS_TELEMETRY_TIMELINE_COMMANDS', true),
        'exceptions'  => env('SQS_TELEMETRY_TIMELINE_EXCEPTIONS', true),
        'logs'        => env('SQS_TELEMETRY_TIMELINE_LOGS', true),
    ],
];
