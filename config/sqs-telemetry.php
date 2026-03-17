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
    */
    'aws' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
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
    */
    'timeline' => [
        'db'          => env('SQS_TELEMETRY_TIMELINE_DB', true),
        'db_bindings' => true,
        'http'        => env('SQS_TELEMETRY_TIMELINE_HTTP', true),
        'cache'       => env('SQS_TELEMETRY_TIMELINE_CACHE', true),
        'commands'    => env('SQS_TELEMETRY_TIMELINE_COMMANDS', true),
        'exceptions'  => env('SQS_TELEMETRY_TIMELINE_EXCEPTIONS', true),
    ],
];
