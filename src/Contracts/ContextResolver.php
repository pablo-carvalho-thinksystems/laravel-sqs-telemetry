<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Contracts;

interface ContextResolver
{
    /**
     * Fields merged into every telemetry message.
     *
     * Called once per flush, never per message. Implementations run while the
     * request is being torn down, so they must not throw and must not perform
     * I/O — return what is already in memory.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array;
}
