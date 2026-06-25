<?php

declare(strict_types = 1);

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Tests\Fixtures\Support\FunctionOverrides;

/**
 * Base test case for the laravel-sse package.
 *
 * Registers no additional service providers — the SSE package ships no provider
 * of its own. FunctionOverrides are reset after each test so namespace-scoped
 * stubs do not bleed between test methods.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class TestCase extends OrchestraTestCase
{
    /** The number of nanoseconds in one second. */
    private const int NS_PER_SECOND = 1000000000;

    /** @var int The fake monotonic clock value, in nanoseconds. */
    private int $monotonicClock = 0;

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        FunctionOverrides::reset();

        parent::tearDown();
    }

    /**
     * Install a controllable monotonic clock in place of hrtime(true).
     *
     * EventStream measures elapsed time with hrtime(true), which time-travel
     * helpers cannot move. The clock starts at a non-zero origin so elapsed
     * arithmetic (now - start) is distinguishable from a sign flip, and is
     * advanced explicitly via {@see advanceClock()}.
     *
     * @param  int  $originSeconds
     * @return void
     */
    protected function fakeMonotonicClock(int $originSeconds = 1000): void
    {
        $this->monotonicClock = $originSeconds * self::NS_PER_SECOND;

        FunctionOverrides::set('hrtime', fn (): int => $this->monotonicClock);
    }

    /**
     * Advance the fake monotonic clock by the given number of seconds.
     *
     * @param  int  $seconds
     * @return void
     */
    protected function advanceClock(int $seconds): void
    {
        $this->monotonicClock += $seconds * self::NS_PER_SECOND;
    }

    /**
     * Advance the fake monotonic clock by the given number of nanoseconds.
     *
     * Sub-second control is needed to exercise the duration-deadline arithmetic
     * at a finer resolution than {@see advanceClock()} allows.
     *
     * @param  int  $nanoseconds
     * @return void
     */
    protected function advanceClockNanoseconds(int $nanoseconds): void
    {
        $this->monotonicClock += $nanoseconds;
    }
}
