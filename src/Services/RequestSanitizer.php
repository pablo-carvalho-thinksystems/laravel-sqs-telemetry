<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

/**
 * Strips credentials out of anything headed for the queue.
 *
 * Shared by every capture path so the redaction rules cannot drift apart: a
 * header that is secret in a middleware-captured request is just as secret in
 * one reconstructed at shutdown.
 */
class RequestSanitizer
{
    /**
     * @var array<int, string>
     */
    protected $sensitiveHeaders = ['authorization', 'cookie', 'php-auth-pw'];

    /**
     * @var array<int, string>
     */
    protected $sensitiveFields = ['password', 'password_confirmation', 'token', 'secret', 'authorization'];

    /**
     * Drop credential-bearing headers and flatten the rest.
     *
     * @param array $headers
     * @return array
     */
    public function headers(array $headers): array
    {
        foreach ($this->sensitiveHeaders as $key) {
            unset($headers[$key]);
            unset($headers[strtolower($key)]);
        }

        return array_map(function ($value) {
            return is_array($value) ? implode(', ', $value) : $value;
        }, $headers);
    }

    /**
     * Mask credential-bearing fields, keeping the key so its presence is still
     * visible in the payload.
     *
     * @param array $payload
     * @return array
     */
    public function payload(array $payload): array
    {
        foreach ($this->sensitiveFields as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = '********';
            }
        }

        return $payload;
    }
}
