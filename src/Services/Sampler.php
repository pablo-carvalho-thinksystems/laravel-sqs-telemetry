<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

class Sampler
{
    /**
     * Decision for the current request. Null until first asked.
     *
     * @var bool|null
     */
    protected $decision;

    /**
     * Whether this request should record telemetry.
     *
     * The draw happens once and is then reused, so every listener in a request
     * agrees: a sampled request records a complete timeline, an unsampled one
     * records nothing at all. Sampling per event would produce timelines with
     * holes in them, which are worse than no timeline.
     *
     * @return bool
     */
    public function shouldRecord(): bool
    {
        if ($this->decision !== null) {
            return $this->decision;
        }

        if (! config('sqs-telemetry.enabled', true)) {
            return $this->decision = false;
        }

        // Sampling exists to protect a hot request path. Console runs are few
        // and each one is individually interesting, so they are always kept —
        // otherwise a sampled-out command would ship an entry with an empty
        // timeline, which is worse than either extreme.
        if ($this->runningInConsole()) {
            return $this->decision = true;
        }

        $rate = (float) config('sqs-telemetry.sampling.rate', 1.0);

        if ($rate >= 1.0) {
            return $this->decision = true;
        }

        if ($rate <= 0.0) {
            return $this->decision = false;
        }

        return $this->decision = (mt_rand(1, 1000000) <= (int) round($rate * 1000000));
    }

    /**
     * Whether an exception must be recorded even on an unsampled request.
     *
     * @return bool
     */
    public function shouldRecordExceptions(): bool
    {
        if (! config('sqs-telemetry.enabled', true)) {
            return false;
        }

        if (config('sqs-telemetry.sampling.always_record_exceptions', true)) {
            return true;
        }

        return $this->shouldRecord();
    }

    /**
     * Promote an unsampled request to a recorded one.
     *
     * Used when something worth keeping happens (an exception) after the draw
     * already came up negative, so the events that follow are not discarded.
     *
     * @return void
     */
    public function forceRecord(): void
    {
        $this->decision = true;
    }

    /**
     * @return bool
     */
    protected function runningInConsole(): bool
    {
        try {
            return (bool) app()->runningInConsole();
        } catch (\Throwable $e) {
            return in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
        }
    }

    /**
     * Clear the decision so the next request draws again.
     *
     * Only relevant on long-lived workers, where the singleton outlives the
     * request that made the draw.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->decision = null;
    }
}
