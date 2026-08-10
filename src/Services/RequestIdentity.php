<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

/**
 * One identifier per request, shared by everything it produces.
 *
 * A single request can emit several messages — the request itself, an
 * exception, a handful of logs. They only become a story when the consumer can
 * tell they came from the same place, which is what this id is for.
 *
 * Distinct from the per-message id: that one exists to deduplicate, this one to
 * correlate.
 */
class RequestIdentity
{
    /**
     * @var string|null
     */
    protected $id;

    /**
     * Identifier for the current request, created on first use.
     *
     * @return string
     */
    public function id(): string
    {
        if ($this->id === null) {
            $this->id = self::uuid4();
        }

        return $this->id;
    }

    /**
     * Drop the identifier so the next request gets its own.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->id = null;
    }

    /**
     * RFC 4122 version 4 UUID.
     *
     * Hand-rolled rather than pulled from a UUID library: the package is
     * deliberately thin, and this is the only place it needs one.
     *
     * @return string
     */
    public static function uuid4(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (\Exception $e) {
            // random_bytes only fails when the platform has no CSPRNG. An id
            // that is merely unique is still better than no id at all.
            $bytes = pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand());
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
