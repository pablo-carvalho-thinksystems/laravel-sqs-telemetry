<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

/**
 * Reconstructs a request entry from PHP's own superglobals.
 *
 * Some hosts boot the framework but never route the request through its HTTP
 * kernel, so the middleware never runs and the request goes unrecorded. Acorn
 * inside WordPress is the case this exists for: it bootstraps the kernel for
 * every request, then hands `/wp-admin`, `/wp-json`, `wp-login.php` and any
 * `.php` path straight back to WordPress. Those requests are invisible to a
 * middleware and are frequently the busiest ones on the site.
 *
 * Everything here comes from PHP itself rather than from the framework's
 * Request object, because on those paths there is no routed request to read.
 */
class ServerRequestSnapshot
{
    /**
     * @var RequestSanitizer
     */
    protected $sanitizer;

    /**
     * @var TimelineContext
     */
    protected $timeline;

    public function __construct(RequestSanitizer $sanitizer, TimelineContext $timeline)
    {
        $this->sanitizer = $sanitizer;
        $this->timeline = $timeline;
    }

    /**
     * Whether this process is serving an HTTP request at all.
     *
     * @return bool
     */
    public function isHttpRequest(): bool
    {
        if (in_array($this->sapi(), ['cli', 'phpdbg', 'embed'], true)) {
            return false;
        }

        return isset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * Seam so both branches of {@see isHttpRequest()} stay reachable in tests,
     * which necessarily run under the CLI SAPI.
     *
     * @return string
     */
    protected function sapi(): string
    {
        return PHP_SAPI;
    }

    /**
     * Build the request entry.
     *
     * @param string $project
     * @return array<string, mixed>
     */
    public function capture(string $project): array
    {
        $status = function_exists('http_response_code') ? http_response_code() : null;

        return [
            'project' => $project,
            'url' => $this->url(),
            'method' => $this->server('REQUEST_METHOD', 'GET'),
            'ip' => $this->server('REMOTE_ADDR'),
            'user_agent' => $this->server('HTTP_USER_AGENT'),
            'status_code' => is_int($status) ? $status : null,
            'execution_time' => $this->executionTime(),
            'timestamp' => now()->toIso8601String(),
            'payload' => $this->sanitizer->payload($this->requestPayload()),
            'headers' => $this->sanitizer->headers($this->headers()),
            'timeline' => $this->timeline->getTimeline(),
            // Kept so these can be told apart in the queue: they carry no
            // framework routing information and their timeline starts at boot
            // rather than at the middleware.
            'capture_source' => 'shutdown',
        ];
    }

    /**
     * Absolute URL of the current request.
     *
     * @return string
     */
    protected function url(): string
    {
        $https = $this->server('HTTPS');
        $scheme = ($https !== null && $https !== 'off') ? 'https' : 'http';

        if ($this->server('HTTP_X_FORWARDED_PROTO') === 'https') {
            $scheme = 'https';
        }

        $host = $this->server('HTTP_HOST', $this->server('SERVER_NAME', 'localhost'));
        $uri = $this->server('REQUEST_URI', '/');

        return $scheme . '://' . $host . $uri;
    }

    /**
     * Milliseconds elapsed since PHP began handling the request.
     *
     * `REQUEST_TIME_FLOAT` predates the framework, so this covers the whole
     * request rather than the slice the framework was aware of.
     *
     * @return float
     */
    protected function executionTime(): float
    {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        if (! is_float($start) && ! is_int($start)) {
            $start = defined('LARAVEL_START') ? LARAVEL_START : null;
        }

        if ($start === null) {
            return 0.0;
        }

        return round((microtime(true) - (float) $start) * 1000, 2);
    }

    /**
     * Query string and form fields, without the raw request body.
     *
     * @return array<string, mixed>
     */
    protected function requestPayload(): array
    {
        $get = is_array($_GET) ? $_GET : [];
        $post = is_array($_POST) ? $_POST : [];

        return array_merge($get, $post);
    }

    /**
     * Request headers, rebuilt from the `HTTP_*` server entries.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (! is_string($key) || strpos($key, 'HTTP_') !== 0) {
                continue;
            }

            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = is_scalar($value) ? (string) $value : '';
        }

        return $headers;
    }

    /**
     * @param string $key
     * @param string|null $default
     * @return string|null
     */
    protected function server(string $key, ?string $default = null): ?string
    {
        $value = $_SERVER[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
