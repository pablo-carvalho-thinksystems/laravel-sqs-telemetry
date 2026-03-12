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
];
