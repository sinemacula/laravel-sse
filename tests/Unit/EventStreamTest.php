<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Sse\Concerns\RespondsWithEventStream;
use SineMacula\Sse\Emitter;
use SineMacula\Sse\Enums\StreamTerminationReason;
use SineMacula\Sse\EventStream;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

// Test-only patterns the production-oriented rules do not fit: mutable public
// spy properties, contract-bound override stubs with unused parameters, a bool
// expose-helper, inline @var assertions, and a large comprehensive test class.
// phpcs:disable SineMacula.Classes.RequireReadonlyPublicProperty.Mutable
// phpcs:disable SineMacula.NamingConventions.BooleanMethodName.NotPredicate
// phpcs:disable SineMacula.Metrics.MaxMethodCount.TooManyMethods
// phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter
// phpcs:disable Squiz.Commenting.InlineComment.DocBlock

/**
 * Tests for the SSE EventStream.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1448")
 *
 * @internal
 */
#[CoversClass(EventStream::class)]
final class EventStreamTest extends TestCase
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
        FunctionOverrides::set('ob_get_status', fn (): array => []);
        FunctionOverrides::set('sleep', fn () => 0);
        FunctionOverrides::set('connection_aborted', fn (): int => 1);
    }

    /**
     * Test that toResponse returns a StreamedResponse instance.
     *
     * @return void
     */
    public function testToResponseReturnsStreamedResponse(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null);

        self::assertInstanceOf(StreamedResponse::class, $response);
    }

    /**
     * Test that toResponse sets the required SSE headers.
     *
     * @return void
     */
    public function testToResponseSetsSseHeaders(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null);

        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));

        $cacheControl = $response->headers->get('Cache-Control');

        self::assertStringContainsString('no-cache', $cacheControl);
        self::assertStringContainsString('no-transform', $cacheControl);
        self::assertSame('keep-alive', $response->headers->get('Connection'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    /**
     * Test that toResponse includes custom headers alongside SSE headers, with
     * SSE headers taking precedence over conflicting custom headers.
     *
     * @return void
     */
    public function testToResponseAcceptsCustomHeaders(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null, headers: [
            'X-Stream-Id'       => 'abc123',
            'Content-Type'      => 'application/json',
            'Cache-Control'     => 'max-age=3600',
            'Connection'        => 'close',
            'X-Accel-Buffering' => 'yes',
        ]);

        $cacheControl = (string) $response->headers->get('Cache-Control');

        self::assertSame('abc123', $response->headers->get('X-Stream-Id'));
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache, no-transform', $cacheControl);
        self::assertStringNotContainsString('max-age', $cacheControl);
        self::assertSame('keep-alive', $response->headers->get('Connection'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    /**
     * Test that toResponse accepts a custom HTTP status code.
     *
     * @return void
     */
    public function testToResponseAcceptsCustomStatus(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null, status: 202);

        self::assertSame(202, $response->getStatusCode());
    }

    /**
     * Test that zero is accepted for every timing input - it is the documented
     * unbounded / no-delay sentinel, not a rejected value.
     *
     * @return void
     */
    public function testZeroIsAcceptedForEveryTimingInput(): void
    {
        $stream   = new EventStream(0, 0, 0);
        $response = $stream->toResponse(fn () => null, 0);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Test that the constructor rejects a negative heartbeat interval.
     *
     * @return void
     */
    public function testConstructorRejectsNegativeHeartbeatInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventStream(heartbeatInterval: -1);
    }

    /**
     * Test that the constructor rejects a negative maximum duration.
     *
     * @return void
     */
    public function testConstructorRejectsNegativeMaxDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventStream(maxDuration: -1);
    }

    /**
     * Test that the constructor rejects a negative maximum iteration count.
     *
     * @return void
     */
    public function testConstructorRejectsNegativeMaxIterations(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventStream(maxIterations: -1);
    }

    /**
     * Test that toResponse rejects a negative polling interval.
     *
     * @return void
     */
    public function testToResponseRejectsNegativeInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new EventStream)->toResponse(fn () => null, -1);
    }

    /**
     * Test that the callback is executed during stream content delivery.
     *
     * @return void
     */
    public function testStreamExecutesCallback(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        $callbackRan = false;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callbackRan): void {
            $callbackRan = true;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertTrue($callbackRan);
    }

    /**
     * Test that the stream emits an initial keep-alive comment.
     *
     * @return void
     */
    public function testStreamEmitsInitialKeepAliveComment(): void
    {
        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertStringStartsWith(self::SSE_COMMENT, (string) $output);
    }

    /**
     * Test that a heartbeat comment is emitted on each poll whose elapsed time
     * has crossed the configured interval.
     *
     * @return void
     */
    public function testStreamEmitsHeartbeatAfterInterval(): void
    {
        $this->fakeMonotonicClock();

        FunctionOverrides::set('connection_aborted', fn (): int => 0);

        $stream   = new EventStream(heartbeatInterval: 5, maxIterations: 2);
        $response = $stream->toResponse(function (): void {
            $this->advanceClock(6);
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        // One initial keep-alive comment plus one heartbeat per poll: each poll
        // advances 6s, crossing the 5s interval every time.
        self::assertSame(3, $commentCount);
    }

    /**
     * Test that the loop breaks when connection_aborted returns truthy on the
     * first check, preventing the callback from executing.
     *
     * @return void
     */
    public function testStreamBreaksOnConnectionAborted(): void
    {
        FunctionOverrides::set('connection_aborted', fn (): int => 1);

        $callbackRan = false;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callbackRan): void {
            $callbackRan = true;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertFalse($callbackRan);
    }

    /**
     * Test that an error event is emitted and the loop breaks when the callback
     * throws an exception.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testStreamEmitsErrorEventWhenCallbackThrows(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $callCount = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callCount): void {
            $callCount++;
            throw new \RuntimeException('Simulated stream failure');
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertStringContainsString("event: error\ndata: An error occurred\n\n", (string) $output);
        self::assertSame(1, $callCount);
    }

    /**
     * Test that the error event does not expose internal exception details such
     * as the exception message or class name.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testStreamErrorEventDoesNotExposeInternalDetails(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(function (): void {
            throw new \RuntimeException('Secret internal database error XYZ');
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertStringNotContainsString('Secret internal database error XYZ', (string) $output);
        self::assertStringNotContainsString(\RuntimeException::class, (string) $output);
        self::assertStringContainsString('data: An error occurred', (string) $output);
    }

    /**
     * Test that the emitter is passed to callbacks that accept a parameter.
     *
     * @return void
     */
    public function testStreamPassesEmitterWhenCallbackAcceptsParameter(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        $receivedEmitter = null;

        $stream   = new EventStream;
        $response = $stream->toResponse(function (Emitter $emitter) use (&$receivedEmitter): void {
            $receivedEmitter = $emitter;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertInstanceOf(Emitter::class, $receivedEmitter);
    }

    /**
     * Test that callbacks with no parameters are called without arguments.
     *
     * @return void
     */
    public function testStreamDoesNotPassEmitterWhenCallbackAcceptsNoParameters(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        $argsReceived = null;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$argsReceived): void {
            $argsReceived = func_get_args();
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame([], $argsReceived);
    }

    /**
     * Test that a callback whose only parameter is optional still receives the
     * emitter. Detection is by declared parameter count, not by whether the
     * parameter is required.
     *
     * @return void
     */
    public function testStreamPassesEmitterWhenCallbackFirstParameterIsOptional(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        $receivedEmitter = null;

        $stream   = new EventStream;
        $response = $stream->toResponse(function (?Emitter $emitter = null) use (&$receivedEmitter): void {
            $receivedEmitter = $emitter;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertInstanceOf(Emitter::class, $receivedEmitter);
    }

    /**
     * Test that the default heartbeat interval is twenty seconds.
     *
     * @return void
     */
    public function testDefaultHeartbeatIntervalIsTwenty(): void
    {
        $this->fakeMonotonicClock();

        FunctionOverrides::set('connection_aborted', fn (): int => 0);

        $stream   = new EventStream(maxIterations: 1);
        $response = $stream->toResponse(function (): void {
            $this->advanceClock(19);
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        // Only the initial keep-alive comment: 19s has not crossed the default
        // 20s interval, so no heartbeat fires.
        self::assertSame(1, $commentCount);
    }

    /**
     * Test that a custom heartbeat interval is respected.
     *
     * @return void
     */
    public function testCustomHeartbeatIntervalIsRespected(): void
    {
        $this->fakeMonotonicClock();

        FunctionOverrides::set('connection_aborted', fn (): int => 0);

        $stream   = new EventStream(heartbeatInterval: 5, maxIterations: 2);
        $response = $stream->toResponse(function (): void {
            $this->advanceClock(5);
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        // Each poll advances exactly the 5s interval, so a heartbeat fires on
        // every poll: one initial comment plus two heartbeats.
        self::assertSame(3, $commentCount);
    }

    /**
     * Test that shouldContinueAfterError is overridable by subclasses. When the
     * override returns true, the loop should continue and the callback should
     * run more than once.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testShouldContinueAfterErrorIsOverridableBySubclass(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 5 ? 1 : 0;
        });

        $callCount = 0;

        $stream = new class extends EventStream {
            /**
             * @param  \Throwable  $exception
             * @param  \SineMacula\Sse\Emitter  $emitter
             * @return bool
             */
            #[\Override]
            protected function shouldContinueAfterError(\Throwable $exception, Emitter $emitter): bool
            {
                return true;
            }
        };

        $response = $stream->toResponse(function () use (&$callCount): void {
            $callCount++;
            throw new \RuntimeException('Recoverable error');
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertGreaterThan(1, $callCount);
    }

    /**
     * Test that the recoverable-error path still advances the iteration count,
     * evaluates the ceiling, and sleeps. A callback that keeps throwing while
     * shouldContinueAfterError returns true must self-terminate at its ceiling
     * rather than spin at full CPU with the ceiling never firing.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testRecoverableErrorPathStillAdvancesCeilingAndSleeps(): void
    {
        $aborts = 0;

        // A high backstop prevents a runaway loop if the recovery path
        // regresses, so the assertions fail cleanly rather than hang.
        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 1000 ? 1 : 0;
        });

        $sleeps = 0;

        FunctionOverrides::set('sleep', function () use (&$sleeps): int {
            $sleeps++;

            return 0;
        });

        $callCount = 0;

        $stream = new class (60, 0, 3) extends EventStream {
            /** @var \SineMacula\Sse\Enums\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \Throwable  $exception
             * @param  \SineMacula\Sse\Emitter  $emitter
             * @return bool
             */
            #[\Override]
            protected function shouldContinueAfterError(\Throwable $exception, Emitter $emitter): bool
            {
                return true;
            }

            /**
             * @param  \SineMacula\Sse\Enums\StreamTerminationReason  $reason
             * @return void
             */
            #[\Override]
            protected function onStreamEnd(StreamTerminationReason $reason): void
            {
                $this->endReason = $reason;
            }
        };

        $response = $stream->toResponse(function () use (&$callCount): void {
            $callCount++;

            throw new \RuntimeException('Recoverable error');
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(3, $callCount);
        self::assertSame(2, $sleeps);
        self::assertSame(StreamTerminationReason::MAX_ITERATIONS, $stream->endReason);
    }

    /**
     * Test that onStreamStart is overridable by subclasses. When overridden,
     * the custom output should appear instead of the default keep-alive.
     *
     * @return void
     */
    public function testOnStreamStartIsOverridableBySubclass(): void
    {
        $stream = new class extends EventStream {
            /**
             * @param  \SineMacula\Sse\Emitter  $emitter
             * @return void
             */
            #[\Override]
            protected function onStreamStart(Emitter $emitter): void
            {
                $emitter->emit('started', 'init');
            }
        };

        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertStringStartsWith("event: init\ndata: started\n\n", (string) $output);
        self::assertStringNotContainsString(self::SSE_COMMENT, (string) $output);
    }

    /**
     * Test that onStreamEnd is called after the polling loop exits.
     *
     * @return void
     */
    public function testOnStreamEndIsCalledAfterLoopExits(): void
    {
        $stream = new class extends EventStream {
            /** @var bool */
            public bool $endCalled = false;

            /**
             * @param  \SineMacula\Sse\Enums\StreamTerminationReason  $reason
             * @return void
             */
            #[\Override]
            protected function onStreamEnd(StreamTerminationReason $reason): void
            {
                $this->endCalled = true;
            }
        };

        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertTrue($stream->endCalled);
    }

    /**
     * Test that the Cache-Control header leads with the comma-separated
     * directive list required for SSE responses. Asserting the prefix keeps the
     * test from coupling to any directive Symfony's header bag may append (such
     * as the computed `private`).
     *
     * @return void
     */
    public function testCacheControlHeaderUsesCommaSeparatedDirectives(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null);

        self::assertStringStartsWith('no-cache, no-transform', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * Test that the default heartbeat fires exactly at the twenty-second
     * boundary. Exactly twenty elapsed seconds must emit a heartbeat in
     * addition to the initial keep-alive comment.
     *
     * @return void
     */
    public function testDefaultHeartbeatFiresExactlyAtTwentySecondBoundary(): void
    {
        $this->fakeMonotonicClock();

        FunctionOverrides::set('connection_aborted', fn (): int => 0);

        $stream   = new EventStream(maxIterations: 1);
        $response = $stream->toResponse(function (): void {
            $this->advanceClock(20);
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        // Initial comment plus exactly one heartbeat: 20s elapsed meets the 20s
        // interval on a >= boundary.
        self::assertSame(2, $commentCount);
    }

    /**
     * Test that the loop sleeps exactly once per full iteration and that the
     * default polling interval of one second is forwarded to sleep.
     *
     * @return void
     */
    public function testSleepReceivesDefaultIntervalOncePerIteration(): void
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

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame([1], $sleepArgs);
    }

    /**
     * Test that the loop terminates immediately after the first abort check
     * signals a disconnect and never polls the connection again.
     *
     * @return void
     */
    public function testStreamDoesNotRecheckConnectionAfterFirstAbort(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            if (++$abortCount > 1) {
                throw new \LogicException('Connection polled again after abort');
            }

            return 1;
        });

        $callbackRan = false;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callbackRan): void {
            $callbackRan = true;
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertSame(self::SSE_COMMENT, $output);
        self::assertFalse($callbackRan);
    }

    /**
     * Test that the second per-iteration abort check terminates the loop
     * entirely rather than skipping to a further iteration. The abort sequence
     * reports a disconnect on the second check only; the callback must
     * therefore run exactly once.
     *
     * @return void
     */
    public function testStreamDoesNotResumeAfterBreakOnSecondAbortCheck(): void
    {
        $aborts = [0, 1, 0, 0, 1];
        $index  = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$index, $aborts): int {
            return $aborts[$index++] ?? 1;
        });

        $callCount = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callCount): void {
            $callCount++;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(1, $callCount);
    }

    /**
     * Test that each iteration flushes the active output buffer via ob_flush
     * when a flushable buffer is active.
     *
     * @return void
     */
    public function testFlushOutputFlushesActiveOutputBuffer(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        FunctionOverrides::set('ob_get_status', fn (): array => ['flags' => PHP_OUTPUT_HANDLER_FLUSHABLE]);

        $obFlushCount = 0;

        FunctionOverrides::set('ob_flush', function () use (&$obFlushCount): void {
            $obFlushCount++;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(1, $obFlushCount);
    }

    /**
     * Test that ob_flush is not called when no output buffer is active.
     *
     * @return void
     */
    public function testFlushOutputSkipsObFlushWhenNoBufferIsActive(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        FunctionOverrides::set('ob_get_status', fn (): array => []);

        $obFlushCount = 0;

        FunctionOverrides::set('ob_flush', function () use (&$obFlushCount): void {
            $obFlushCount++;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(0, $obFlushCount);
    }

    /**
     * Test that ob_flush is not called when the active buffer is not flushable.
     * A buffer level alone is not sufficient - the PHP_OUTPUT_HANDLER_FLUSHABLE
     * flag must be set, otherwise ob_flush() would warn.
     *
     * @return void
     */
    public function testFlushOutputSkipsObFlushWhenBufferIsNotFlushable(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        FunctionOverrides::set('ob_get_status', fn (): array => ['flags' => 0]);

        $obFlushCount = 0;

        FunctionOverrides::set('ob_flush', function () use (&$obFlushCount): void {
            $obFlushCount++;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(0, $obFlushCount);
    }

    /**
     * Test that the system output buffer is flushed once per iteration in
     * addition to the flush performed by the initial keep-alive comment.
     *
     * @return void
     */
    public function testFlushOutputFlushesSystemBufferEachIteration(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        $flushCount = 0;

        FunctionOverrides::set('flush', function () use (&$flushCount): void {
            $flushCount++;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(2, $flushCount);
    }

    /**
     * Test that shouldContinueAfterError is callable from a subclass, reports
     * the exception through the application exception handler, emits the
     * generic error event, and signals the loop to stop.
     *
     * @return void
     */
    public function testShouldContinueAfterErrorReportsExceptionAndIsCallableFromSubclass(): void
    {
        $exception = new \RuntimeException('Simulated stream failure');

        /** @var \Illuminate\Contracts\Debug\ExceptionHandler&\Mockery\MockInterface $handler */
        $handler = \Mockery::mock(ExceptionHandler::class);
        // @phpstan-ignore method.notFound (Mockery's fluent interface)
        $handler->shouldReceive('report')->once()->with($exception);

        assert($this->app !== null);

        $this->app->instance(ExceptionHandler::class, $handler);

        $stream = new class extends EventStream {
            /**
             * @param  \Throwable  $exception
             * @param  \SineMacula\Sse\Emitter  $emitter
             * @return bool
             */
            public function exposeShouldContinueAfterError(\Throwable $exception, Emitter $emitter): bool
            {
                return $this->shouldContinueAfterError($exception, $emitter);
            }
        };

        ob_start();
        $result = $stream->exposeShouldContinueAfterError($exception, new Emitter);
        $output = ob_get_clean();

        self::assertFalse($result);
        self::assertStringContainsString("event: error\ndata: An error occurred\n\n", (string) $output);
    }

    /**
     * Test that onStreamStart is callable from a subclass and emits the initial
     * keep-alive comment by default.
     *
     * @return void
     */
    public function testOnStreamStartIsCallableFromSubclass(): void
    {
        $stream = new class extends EventStream {
            /**
             * @param  \SineMacula\Sse\Emitter  $emitter
             * @return void
             */
            public function exposeOnStreamStart(Emitter $emitter): void
            {
                $this->onStreamStart($emitter);
            }
        };

        ob_start();
        $stream->exposeOnStreamStart(new Emitter);
        $output = ob_get_clean();

        self::assertSame(self::SSE_COMMENT, $output);
    }

    /**
     * Test that onStreamEnd is callable from a subclass and produces no output
     * by default.
     *
     * @return void
     */
    public function testOnStreamEndIsCallableFromSubclass(): void
    {
        $stream = new class extends EventStream {
            /**
             * @return void
             */
            public function exposeOnStreamEnd(): void
            {
                $this->onStreamEnd(StreamTerminationReason::CLIENT_DISCONNECT);
            }
        };

        ob_start();
        $stream->exposeOnStreamEnd();
        $output = ob_get_clean();

        self::assertSame('', $output);
    }

    /**
     * Test that the loop breaks on the second abort check within an iteration.
     * The first and second checks pass, allowing the callback to run, then the
     * third check (second per-iteration check) triggers the break.
     *
     * @return void
     */
    public function testStreamBreaksOnSecondAbortCheck(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $callCount = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$callCount): void {
            $callCount++;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(1, $callCount);
    }

    /**
     * Test that a stream configured with a maximum duration self-terminates
     * once the elapsed time reaches the ceiling, even when the client never
     * disconnects.
     *
     * @return void
     */
    public function testStreamSelfTerminatesAtConfiguredMaxDuration(): void
    {
        $this->fakeMonotonicClock();

        $polls  = 0;
        $aborts = 0;

        // The client never aborts; a high backstop prevents a runaway loop if
        // the cap regresses, so the assertion fails cleanly, not hangs.
        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 1000 ? 1 : 0;
        });

        $stream   = new EventStream(maxDuration: 5);
        $response = $stream->toResponse(function () use (&$polls): void {
            $polls++;
            $this->advanceClock(1);
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        // 5s ceiling at 1s per poll: the duration check trips before the sixth
        // poll, so the callback runs exactly five times.
        self::assertSame(5, $polls);
    }

    /**
     * Test that a stream configured with a maximum iteration count
     * self-terminates once that many polls have run.
     *
     * @return void
     */
    public function testStreamSelfTerminatesAtConfiguredMaxIterations(): void
    {
        $polls  = 0;
        $aborts = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 1000 ? 1 : 0;
        });

        $stream   = new EventStream(60, 0, 3);
        $response = $stream->toResponse(function () use (&$polls): void {
            $polls++;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(3, $polls);
    }

    /**
     * Test that reaching the duration ceiling runs the end-of-stream lifecycle
     * exactly once and reports the MaxDuration termination reason (graceful
     * close).
     *
     * @return void
     */
    public function testMaxDurationTerminationRunsOnStreamEndOnceWithReason(): void
    {
        $this->fakeMonotonicClock();

        $polls  = 0;
        $aborts = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 1000 ? 1 : 0;
        });

        $stream = new class (60, 5) extends EventStream {
            /** @var int */
            public int $endCount = 0;

            /** @var \SineMacula\Sse\Enums\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \SineMacula\Sse\Enums\StreamTerminationReason  $reason
             * @return void
             */
            #[\Override]
            protected function onStreamEnd(StreamTerminationReason $reason): void
            {
                $this->endCount++;
                $this->endReason = $reason;
            }
        };

        $response = $stream->toResponse(function () use (&$polls): void {
            $polls++;
            $this->advanceClock(1);
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(1, $stream->endCount);
        self::assertSame(StreamTerminationReason::MAX_DURATION, $stream->endReason);
    }

    /**
     * Test that reaching the iteration ceiling reports the MaxIterations
     * termination reason.
     *
     * @return void
     */
    public function testMaxIterationsTerminationReportsMaxIterationsReason(): void
    {
        $polls  = 0;
        $aborts = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 1000 ? 1 : 0;
        });

        $stream = new class (60, 0, 3) extends EventStream {
            /** @var \SineMacula\Sse\Enums\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \SineMacula\Sse\Enums\StreamTerminationReason  $reason
             * @return void
             */
            #[\Override]
            protected function onStreamEnd(StreamTerminationReason $reason): void
            {
                $this->endReason = $reason;
            }
        };

        $response = $stream->toResponse(function () use (&$polls): void {
            $polls++;
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(StreamTerminationReason::MAX_ITERATIONS, $stream->endReason);
    }

    /**
     * Test that a client disconnect reports the ClientDisconnect termination
     * reason, distinct from a ceiling-reached close.
     *
     * @return void
     */
    public function testClientDisconnectTerminationReportsClientDisconnectReason(): void
    {
        FunctionOverrides::set('connection_aborted', fn (): int => 1);

        $stream = new class extends EventStream {
            /** @var \SineMacula\Sse\Enums\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \SineMacula\Sse\Enums\StreamTerminationReason  $reason
             * @return void
             */
            #[\Override]
            protected function onStreamEnd(StreamTerminationReason $reason): void
            {
                $this->endReason = $reason;
            }
        };

        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(StreamTerminationReason::CLIENT_DISCONNECT, $stream->endReason);
    }

    /**
     * Test that an unrecoverable error reports the Error termination reason.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testErrorTerminationReportsErrorReason(): void
    {
        FunctionOverrides::set('connection_aborted', fn (): int => 0);

        $stream = new class extends EventStream {
            /** @var \SineMacula\Sse\Enums\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \SineMacula\Sse\Enums\StreamTerminationReason  $reason
             * @return void
             */
            #[\Override]
            protected function onStreamEnd(StreamTerminationReason $reason): void
            {
                $this->endReason = $reason;
            }
        };

        $response = $stream->toResponse(function (): void {
            throw new \RuntimeException('boom');
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(StreamTerminationReason::ERROR, $stream->endReason);
    }

    /**
     * Test that a default-configured stream (no ceiling) does not
     * self-terminate even when the clock is advanced far beyond any plausible
     * ceiling - it ends only on client disconnect, preserving php-fpm
     * behaviour.
     *
     * @return void
     */
    public function testDefaultUnboundedStreamDoesNotSelfTerminate(): void
    {
        $this->fakeMonotonicClock();

        $polls  = 0;
        $aborts = 0;

        // The client eventually disconnects; nothing else may end the stream.
        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 50 ? 1 : 0;
        });

        $stream = new class extends EventStream {
            /** @var \SineMacula\Sse\Enums\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \SineMacula\Sse\Enums\StreamTerminationReason  $reason
             * @return void
             */
            #[\Override]
            protected function onStreamEnd(StreamTerminationReason $reason): void
            {
                $this->endReason = $reason;
            }
        };

        $response = $stream->toResponse(function () use (&$polls): void {
            $polls++;
            $this->advanceClock(3600);
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        // The clock advanced thousands of seconds per poll yet the stream ran
        // until the client disconnected - it did not self-terminate.
        self::assertGreaterThan(0, $polls);
        self::assertSame(StreamTerminationReason::CLIENT_DISCONNECT, $stream->endReason);
    }

    /**
     * Test that the RespondsWithEventStream trait threads its MAX_DURATION
     * constant into the constructed EventStream so the cap is reachable from
     * the trait seam.
     *
     * @return void
     */
    public function testRespondWithEventStreamThreadsMaxDurationFromTraitMethod(): void
    {
        $this->fakeMonotonicClock();

        $polls  = 0;
        $aborts = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 1000 ? 1 : 0;
        });

        $controller = new class {
            use RespondsWithEventStream;

            /**
             * @return int
             */
            protected function maxStreamDuration(): int
            {
                return 5;
            }

            /**
             * @param  callable(): void  $callback
             * @return \Symfony\Component\HttpFoundation\StreamedResponse
             */
            public function run(callable $callback): StreamedResponse
            {
                return $this->respondWithEventStream($callback);
            }
        };

        $response = $controller->run(function () use (&$polls): void {
            $polls++;
            $this->advanceClock(1);
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(5, $polls);
    }

    /**
     * Test that the package ships no config file and no service provider - the
     * cap lives on the existing seams only.
     *
     * @return void
     */
    public function testPackageShipsNoConfigFileOrServiceProvider(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertDirectoryDoesNotExist($root . '/config');

        $providers = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
            if (!str_ends_with($file->getFilename(), 'ServiceProvider.php')) {
                continue;
            }

            $providers[] = $file->getPathname();
        }

        self::assertSame([], $providers);
    }

    /**
     * Test that toResponse defaults to a 200 status code.
     *
     * @return void
     */
    public function testToResponseDefaultsToStatus200(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Test that the heartbeat timestamp is reset after a comment fires, so a
     * heartbeat does not repeat on every subsequent sub-interval iteration.
     *
     * @return void
     */
    public function testHeartbeatTimestampResetsBetweenIntervals(): void
    {
        $this->fakeMonotonicClock();

        FunctionOverrides::set('connection_aborted', fn (): int => 0);

        // Each poll advances less than the 10s interval, so without the
        // post-heartbeat timestamp reset the heartbeat would fire on every poll
        // once the first interval elapses, inflating the comment count.
        $stream   = new EventStream(heartbeatInterval: 10, maxIterations: 6);
        $response = $stream->toResponse(function (): void {
            $this->advanceClock(4);
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        // Initial keep-alive plus a heartbeat at polls 3 and 6 (4s per poll,
        // 10s interval, timestamp reset after each fire). A missing reset would
        // fire on every poll from the third on, producing five comments.
        self::assertSame(3, $commentCount);
    }

    /**
     * Test that the poll sleep is capped to the time remaining until the
     * duration ceiling, so a long polling interval cannot carry the stream past
     * maxDuration. With a 5s ceiling and a 60s interval the stream must end at
     * 5s, having slept only the 5s remaining rather than the full 60s.
     *
     * @return void
     */
    public function testMaxDurationCapsSleepToRemainingTime(): void
    {
        $this->fakeMonotonicClock();

        FunctionOverrides::set('connection_aborted', fn (): int => 0);

        $sleeps = [];

        FunctionOverrides::set('sleep', function (int $seconds) use (&$sleeps): int {
            $sleeps[] = $seconds;
            $this->advanceClock($seconds);

            return 0;
        });

        $polls = 0;

        $stream   = new EventStream(20, 5, 0);
        $response = $stream->toResponse(function () use (&$polls): void {
            $polls++;
        }, interval: 60);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        // One poll, then a single sleep capped to the 5s remaining (not the 60s
        // interval), after which the loop-top duration check ends the stream.
        self::assertSame(1, $polls);
        self::assertSame([5], $sleeps);
    }

    /**
     * Test that the full interval is slept while the deadline is far off - the
     * cap selects the interval, not the larger time-to-deadline. A 2s interval
     * under a 100s ceiling must sleep 2s, not 100s.
     *
     * @return void
     */
    public function testMaxDurationSleepUsesIntervalWhenDeadlineIsFarOff(): void
    {
        $this->fakeMonotonicClock();

        $aborts = 0;

        // Stay connected through the first poll's two checks, then disconnect
        // on the next iteration's top check so one sleep is seen.
        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 3 ? 1 : 0;
        });

        $sleeps = [];

        FunctionOverrides::set('sleep', function (int $seconds) use (&$sleeps): int {
            $sleeps[] = $seconds;
            $this->advanceClock($seconds);

            return 0;
        });

        $stream   = new EventStream(20, 100, 0);
        $response = $stream->toResponse(fn () => null, interval: 2);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame([2], $sleeps);
    }

    /**
     * Test that the duration-capped sleep rounds the remaining time up to whole
     * seconds. With 4.2s left to a 10s ceiling the sleep must round up to 5s so
     * the next loop-top check sees the deadline crossed, rather than truncating
     * to 4s and running an extra poll.
     *
     * @return void
     */
    public function testMaxDurationSleepCapRoundsRemainingUpToWholeSeconds(): void
    {
        $this->fakeMonotonicClock();

        FunctionOverrides::set('connection_aborted', fn (): int => 0);

        $sleeps = [];

        FunctionOverrides::set('sleep', function (int $seconds) use (&$sleeps): int {
            $sleeps[] = $seconds;
            $this->advanceClock($seconds);

            return 0;
        });

        $polls = 0;

        $stream   = new EventStream(20, 10, 0);
        $response = $stream->toResponse(function () use (&$polls): void {
            $polls++;
            // Consume 5.8s of monotonic time during the poll, leaving 4.2s.
            $this->advanceClockNanoseconds(5800000000);
        }, interval: 60);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        // ceil(4.2) = 5: one poll, one capped sleep of 5s, then termination. A
        // truncating or rounding cap would sleep 4s and run a second poll.
        self::assertSame(1, $polls);
        self::assertSame([5], $sleeps);
    }

    /**
     * Test that no sleep happens when a poll runs exactly up to the duration
     * deadline. With 5s consumed against a 5s ceiling there is no time left, so
     * the loop returns to its top check without sleeping.
     *
     * @return void
     */
    public function testMaxDurationSkipsSleepWhenDeadlineReachedExactlyDuringPoll(): void
    {
        $this->fakeMonotonicClock();

        FunctionOverrides::set('connection_aborted', fn (): int => 0);

        $sleeps = [];

        FunctionOverrides::set('sleep', function (int $seconds) use (&$sleeps): int {
            $sleeps[] = $seconds;
            $this->advanceClock($seconds);

            return 0;
        });

        $polls = 0;

        $stream   = new EventStream(20, 5, 0);
        $response = $stream->toResponse(function () use (&$polls): void {
            $polls++;
            $this->advanceClock(5);
        }, interval: 60);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(1, $polls);
        self::assertSame([], $sleeps);
    }

    /**
     * Test that no sleep happens when a poll overruns the duration deadline. A
     * poll that consumes more than the remaining time leaves a negative
     * remainder, which must never be passed to sleep().
     *
     * @return void
     */
    public function testMaxDurationSkipsSleepWhenPollOverrunsDeadline(): void
    {
        $this->fakeMonotonicClock();

        FunctionOverrides::set('connection_aborted', fn (): int => 0);

        $sleeps = [];

        FunctionOverrides::set('sleep', function (int $seconds) use (&$sleeps): int {
            $sleeps[] = $seconds;
            $this->advanceClock($seconds);

            return 0;
        });

        $polls = 0;

        $stream   = new EventStream(20, 5, 0);
        $response = $stream->toResponse(function () use (&$polls): void {
            $polls++;
            $this->advanceClock(8);
        }, interval: 60);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        self::assertSame(1, $polls);
        self::assertSame([], $sleeps);
    }
}
