<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

use Throwable;

/**
 * Remembers which exceptions this request already shipped.
 *
 * An uncaught exception reaches the buffer twice: once through the host's
 * reporting hook, and again through the `MessageLogged` listener when the
 * default handler writes it to the log. Both paths are worth keeping — the
 * first carries code context, the second catches exceptions that were handled
 * and only logged — so they share one registry instead of one dropping out.
 */
class ReportedExceptions
{
    /**
     * @var array<string, bool>
     */
    protected $seen = [];

    /**
     * Claim an exception, returning false if it was already claimed.
     *
     * @param Throwable $e
     * @return bool
     */
    public function claim(Throwable $e): bool
    {
        $key = spl_object_hash($e);

        if (isset($this->seen[$key])) {
            return false;
        }

        $this->seen[$key] = true;

        return true;
    }

    /**
     * Forget every claim, so the next request starts clean.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->seen = [];
    }
}
