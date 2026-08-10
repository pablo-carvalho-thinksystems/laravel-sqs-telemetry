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
     * Whether recording was forced rather than drawn.
     *
     * @var bool
     */
    protected $forced = false;

    /**
     * Decision as it stood when reset() last ran.
     *
     * In classic SAPI the provider's terminating() hook resets this singleton
     * BEFORE the shutdown-function fallback asks whether the request was
     * sampled. Without this memo the fallback would draw a second time, and a
     * request that lost the first draw could win the second — inflating the
     * effective rate from p to p + (1-p)·p and shipping "shutdown" captures
     * for routes the middleware already saw.
     *
     * @var bool|null
     */
    protected $lastDecision;

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
     * The decision that applied to the request that just ran — never a redraw.
     *
     * For the shutdown-time capture path only. Reuses the live decision when
     * there is one, then the memo preserved by reset(), and only draws fresh
     * when nothing ever asked during the request (a request with every
     * listener disabled). On long-lived workers the shutdown path does not run
     * per request, so the memo never crosses request boundaries there.
     *
     * @return bool
     */
    public function shouldRecordAtShutdown(): bool
    {
        if ($this->decision !== null) {
            return $this->decision;
        }

        if ($this->lastDecision !== null) {
            return $this->lastDecision;
        }

        return $this->shouldRecord();
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
        $this->forced = true;
    }

    /**
     * Probability with which the current request was selected.
     *
     * Travels with every message so the consumer can weight counts: at a rate
     * of 0.05 each recorded request stands for twenty. Getting this wrong in
     * either direction produces a dashboard that lies — which is why it is
     * reported per message rather than assumed to be the configured value.
     *
     * Returns 1.0 whenever selection was certain: console runs, and requests
     * promoted by an exception. Weighting those by the sampling rate would
     * inflate error counts twentyfold.
     *
     * @return float
     */
    public function effectiveRate(): float
    {
        if ($this->forced || $this->runningInConsole()) {
            return 1.0;
        }

        $rate = (float) config('sqs-telemetry.sampling.rate', 1.0);

        if ($rate >= 1.0) {
            return 1.0;
        }

        return $rate <= 0.0 ? 0.0 : $rate;
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
        if ($this->decision !== null) {
            $this->lastDecision = $this->decision;
        }

        $this->decision = null;
        $this->forced = false;
    }
}
