<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\Sse\Concerns\RespondsWithEventStream;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\RespondsWithEventStreamHarness;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

/**
 * Tests for the RespondsWithEventStream controller trait.
 *
 * The trait is exercised through {@see RespondsWithEventStreamHarness} and
 * anonymous subclasses of it, so the protected seam methods are crossed at a
 * real inheritance boundary - a protected-to-private change on any seam then
 * becomes observable from a subclass.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(RespondsWithEventStream::class)]
final class RespondsWithEventStreamTest extends TestCase
{
    /** @var string The SSE comment wire format used for keep-alive signals. */
    private const string SSE_COMMENT = ":\n\n";

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);
        FunctionOverrides::set('ob_get_level', fn () => 0);
        FunctionOverrides::set('sleep', fn () => 0);
        FunctionOverrides::set('connection_aborted', fn (): int => 1);
    }

    /**
     * Test that the trait returns a StreamedResponse carrying its default 200
     * status from a using class.
     *
     * @return void
     */
    public function testRespondWithEventStreamReturnsStreamedResponseWithDefaultStatus(): void
    {
        $response = (new RespondsWithEventStreamHarness)->stream(fn () => null);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Test that the trait's seam methods remain reachable from a subclass and
     * return their documented defaults. A protected-to-private change on any
     * seam would make the call from the subclass inaccessible, and a change to
     * any default would alter the returned value.
     *
     * @return void
     */
    public function testSeamMethodsAreAccessibleToSubclassesWithDefaults(): void
    {
        $harness = new class extends RespondsWithEventStreamHarness {
            /**
             * @return array{int, int, int}
             */
            public function exposeSeamDefaults(): array
            {
                return [
                    $this->heartbeatInterval(),
                    $this->maxStreamDuration(),
                    $this->maxStreamIterations(),
                ];
            }
        };

        self::assertSame([20, 0, 0], $harness->exposeSeamDefaults());
    }

    /**
     * Test that the trait threads its default one-second polling interval into
     * the stream, so the loop sleeps for exactly one second per iteration.
     *
     * @return void
     */
    public function testDefaultPollingIntervalIsOneSecond(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $sleepArgs = [];

        FunctionOverrides::set('sleep', function (int $seconds) use (&$sleepArgs): int {
            $sleepArgs[] = $seconds;

            return 0;
        });

        $response = (new RespondsWithEventStreamHarness)->stream(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame([1], $sleepArgs);
    }

    /**
     * Test that the trait's default heartbeat interval (twenty seconds) is
     * threaded into the stream: a heartbeat fires once the elapsed time reaches
     * the twenty-second boundary, alongside the initial keep-alive comment.
     *
     * @return void
     */
    public function testDefaultHeartbeatFiresAtTwentySecondBoundary(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $response = (new RespondsWithEventStreamHarness)->stream(function (): void {
            $this->travel(20)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertSame(2, substr_count((string) $output, self::SSE_COMMENT));
    }

    /**
     * Test that the trait's default heartbeat does not fire before the
     * twenty-second boundary: nineteen elapsed seconds emit only the initial
     * keep-alive comment.
     *
     * @return void
     */
    public function testDefaultHeartbeatDoesNotFireBeforeTwentySeconds(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $response = (new RespondsWithEventStreamHarness)->stream(function (): void {
            $this->travel(19)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertSame(1, substr_count((string) $output, self::SSE_COMMENT));
    }

    /**
     * Test that the trait's default ceilings leave the stream unbounded: with
     * the default duration and iteration ceilings of zero, the loop polls until
     * the client disconnects rather than self-terminating after the first poll.
     *
     * @return void
     */
    public function testDefaultCeilingsLeaveTheStreamUnbounded(): void
    {
        $this->travelTo(now());

        $polls  = 0;
        $aborts = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 5 ? 1 : 0;
        });

        $response = (new RespondsWithEventStreamHarness)->stream(function () use (&$polls): void {
            $polls++;
            $this->travel(3600)->seconds();
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        // A ceiling of 1 (duration or iteration) would cap the loop at the
        // first poll; the unbounded defaults run until the client disconnects.
        self::assertSame(2, $polls);
    }

    /**
     * Test that a subclass override of the heartbeat interval is honoured
     * through the trait. The override is only reachable if the trait's seam
     * method remains protected (overridable), so a custom five-second heartbeat
     * fires where the default twenty would not.
     *
     * @return void
     */
    public function testOverriddenHeartbeatIntervalIsHonoured(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $harness = new class extends RespondsWithEventStreamHarness {
            /**
             * @return int
             */
            #[\Override]
            protected function heartbeatInterval(): int
            {
                return 5;
            }
        };

        $response = $harness->stream(function (): void {
            $this->travel(5)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertSame(2, substr_count((string) $output, self::SSE_COMMENT));
    }

    /**
     * Test that a subclass override of the maximum stream duration is honoured
     * through the trait, capping the stream once the elapsed time reaches the
     * configured ceiling.
     *
     * @return void
     */
    public function testOverriddenMaxStreamDurationCapsTheStream(): void
    {
        $this->travelTo(now());

        $polls  = 0;
        $aborts = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 1000 ? 1 : 0;
        });

        $harness = new class extends RespondsWithEventStreamHarness {
            /**
             * @return int
             */
            #[\Override]
            protected function maxStreamDuration(): int
            {
                return 5;
            }
        };

        $response = $harness->stream(function () use (&$polls): void {
            $polls++;
            $this->travel(1)->seconds();
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(5, $polls);
    }

    /**
     * Test that a subclass override of the maximum iteration count is honoured
     * through the trait, capping the stream after the configured number of
     * polls.
     *
     * @return void
     */
    public function testOverriddenMaxStreamIterationsCapsTheStream(): void
    {
        $polls  = 0;
        $aborts = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 1000 ? 1 : 0;
        });

        $harness = new class extends RespondsWithEventStreamHarness {
            /**
             * @return int
             */
            #[\Override]
            protected function maxStreamIterations(): int
            {
                return 3;
            }
        };

        $response = $harness->stream(function () use (&$polls): void {
            $polls++;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(3, $polls);
    }

    /**
     * Test that the trait's respondWithEventStream method is reachable from a
     * subclass. The call only resolves if the seam method remains protected; a
     * private method would be inaccessible across the inheritance boundary.
     *
     * @return void
     */
    public function testRespondWithEventStreamIsCallableFromSubclasses(): void
    {
        $harness = new class extends RespondsWithEventStreamHarness {
            /**
             * @param  callable(): void  $callback
             * @return \Symfony\Component\HttpFoundation\StreamedResponse
             */
            public function streamFromSubclass(callable $callback): StreamedResponse
            {
                return $this->respondWithEventStream($callback);
            }
        };

        $response = $harness->streamFromSubclass(fn () => null);

        self::assertInstanceOf(StreamedResponse::class, $response);
    }
}
