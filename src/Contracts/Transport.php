<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Contracts;

interface Transport
{
    /**
     * Hand off a batch of telemetry messages.
     *
     * Implementations run while a request is being torn down, so they must
     * never throw — losing telemetry is always preferable to affecting the
     * request that produced it.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return void
     */
    public function sendBatch(array $messages): void;
}
