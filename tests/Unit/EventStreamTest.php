<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Sse\Concerns\RespondsWithEventStream;
use SineMacula\Sse\Emitter;
use SineMacula\Sse\EventStream;
use SineMacula\Sse\StreamTerminationReason;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

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
class EventStreamTest extends TestCase
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
        FunctionOverrides::set('sleep', fn (int $_s) => 0);
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

        static::assertInstanceOf(StreamedResponse::class, $response);
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

        static::assertSame('text/event-stream', $response->headers->get('Content-Type'));

        $cacheControl = $response->headers->get('Cache-Control');

        static::assertStringContainsString('no-cache', $cacheControl);
        static::assertStringContainsString('no-transform', $cacheControl);
        static::assertSame('keep-alive', $response->headers->get('Connection'));
        static::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    /**
     * Test that toResponse includes custom headers alongside SSE headers,
     * with SSE headers taking precedence over conflicting custom headers.
     *
     * @return void
     */
    public function testToResponseAcceptsCustomHeaders(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null, headers: [
            'X-Stream-Id'  => 'abc123',
            'Content-Type' => 'application/json',
        ]);

        static::assertSame('abc123', $response->headers->get('X-Stream-Id'));
        static::assertSame('text/event-stream', $response->headers->get('Content-Type'));
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

        static::assertSame(202, $response->getStatusCode());
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

        static::assertTrue($callbackRan);
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

        static::assertStringStartsWith(self::SSE_COMMENT, (string) $output);
    }

    /**
     * Test that a heartbeat comment is emitted after the interval elapses.
     *
     * @return void
     */
    public function testStreamEmitsHeartbeatAfterInterval(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 4 ? 1 : 0;
        });

        $stream   = new EventStream(heartbeatInterval: 5);
        $response = $stream->toResponse(function (): void {
            $this->travel(6)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        static::assertGreaterThanOrEqual(2, $commentCount);
    }

    /**
     * Test that the loop breaks when connection_aborted returns truthy on
     * the first check, preventing the callback from executing.
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

        static::assertFalse($callbackRan);
    }

    /**
     * Test that an error event is emitted and the loop breaks when the
     * callback throws an exception.
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

        static::assertStringContainsString("event: error\ndata: An error occurred\n\n", (string) $output);
        static::assertSame(1, $callCount);
    }

    /**
     * Test that the error event does not expose internal exception details
     * such as the exception message or class name.
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

        static::assertStringNotContainsString('Secret internal database error XYZ', (string) $output);
        static::assertStringNotContainsString(\RuntimeException::class, (string) $output);
        static::assertStringContainsString('data: An error occurred', (string) $output);
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

        static::assertInstanceOf(Emitter::class, $receivedEmitter);
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

        static::assertSame([], $argsReceived);
    }

    /**
     * Test that the default heartbeat interval is twenty seconds.
     *
     * @return void
     */
    public function testDefaultHeartbeatIntervalIsTwenty(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 4 ? 1 : 0;
        });

        $iteration = 0;

        $stream   = new EventStream;
        $response = $stream->toResponse(function () use (&$iteration): void {
            $iteration++;

            if ($iteration === 1) {
                $this->travel(19)->seconds();
            }
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        static::assertSame(1, $commentCount);
    }

    /**
     * Test that a custom heartbeat interval is respected.
     *
     * @return void
     */
    public function testCustomHeartbeatIntervalIsRespected(): void
    {
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 4 ? 1 : 0;
        });

        $stream   = new EventStream(heartbeatInterval: 5);
        $response = $stream->toResponse(function (): void {
            $this->travel(5)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        static::assertGreaterThanOrEqual(2, $commentCount);
    }

    /**
     * Test that handleStreamError is overridable by subclasses. When the
     * override returns true, the loop should continue and the callback
     * should run more than once.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testHandleStreamErrorIsOverridableBySubclass(): void
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
            protected function handleStreamError(\Throwable $exception, Emitter $emitter): bool
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

        static::assertGreaterThan(1, $callCount);
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

        static::assertStringStartsWith("event: init\ndata: started\n\n", (string) $output);
        static::assertStringNotContainsString(self::SSE_COMMENT, (string) $output);
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
             * @param  \SineMacula\Sse\StreamTerminationReason  $reason
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

        static::assertTrue($stream->endCalled);
    }

    /**
     * Test that the Cache-Control header is the exact comma-separated
     * directive list required for SSE responses. Symfony's header bag
     * appends the computed `private` directive.
     *
     * @return void
     */
    public function testCacheControlHeaderUsesCommaSeparatedDirectives(): void
    {
        $stream = new EventStream;

        $response = $stream->toResponse(fn () => null);

        static::assertSame('no-cache, no-transform, private', $response->headers->get('Cache-Control'));
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
        $this->travelTo(now());

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(function (): void {
            $this->travel(20)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        static::assertSame(2, $commentCount);
    }

    /**
     * Test that the loop sleeps exactly once per full iteration and that
     * the default polling interval of one second is forwarded to sleep.
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

        static::assertSame([1], $sleepArgs);
    }

    /**
     * Test that the loop terminates immediately after the first abort
     * check signals a disconnect and never polls the connection again.
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

        static::assertSame(self::SSE_COMMENT, $output);
        static::assertFalse($callbackRan);
    }

    /**
     * Test that the second per-iteration abort check terminates the loop
     * entirely rather than skipping to a further iteration. The abort
     * sequence reports a disconnect on the second check only; the callback
     * must therefore run exactly once.
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

        static::assertSame(1, $callCount);
    }

    /**
     * Test that each iteration flushes the active output buffer via
     * ob_flush when a buffer level is active.
     *
     * @return void
     */
    public function testFlushOutputFlushesActiveOutputBuffer(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 2 ? 1 : 0;
        });

        FunctionOverrides::set('ob_get_level', fn (): int => 1);

        $obFlushCount = 0;

        FunctionOverrides::set('ob_flush', function () use (&$obFlushCount): void {
            $obFlushCount++;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertSame(1, $obFlushCount);
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

        FunctionOverrides::set('ob_get_level', fn (): int => 0);

        $obFlushCount = 0;

        FunctionOverrides::set('ob_flush', function () use (&$obFlushCount): void {
            $obFlushCount++;
        });

        $stream   = new EventStream;
        $response = $stream->toResponse(fn () => null);

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertSame(0, $obFlushCount);
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

        static::assertSame(2, $flushCount);
    }

    /**
     * Test that handleStreamError is callable from a subclass, reports the
     * exception through the application exception handler, emits the
     * generic error event, and signals the loop to stop.
     *
     * @return void
     */
    public function testHandleStreamErrorReportsExceptionAndIsCallableFromSubclass(): void
    {
        $exception = new \RuntimeException('Simulated stream failure');

        /** @var \Illuminate\Contracts\Debug\ExceptionHandler&\Mockery\MockInterface $handler */
        $handler = \Mockery::mock(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        // @phpstan-ignore method.notFound (Mockery's fluent expectation interface is not statically typed)
        $handler->shouldReceive('report')->once()->with($exception);

        assert($this->app !== null);

        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);

        $stream = new class extends EventStream {
            /**
             * @param  \Throwable  $exception
             * @param  \SineMacula\Sse\Emitter  $emitter
             * @return bool
             */
            public function exposeHandleStreamError(\Throwable $exception, Emitter $emitter): bool
            {
                return $this->handleStreamError($exception, $emitter);
            }
        };

        ob_start();
        $result = $stream->exposeHandleStreamError($exception, new Emitter);
        $output = ob_get_clean();

        static::assertFalse($result);
        static::assertStringContainsString("event: error\ndata: An error occurred\n\n", (string) $output);
    }

    /**
     * Test that onStreamStart is callable from a subclass and emits the
     * initial keep-alive comment by default.
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

        static::assertSame(self::SSE_COMMENT, $output);
    }

    /**
     * Test that onStreamEnd is callable from a subclass and produces no
     * output by default.
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
                $this->onStreamEnd(StreamTerminationReason::ClientDisconnect);
            }
        };

        ob_start();
        $stream->exposeOnStreamEnd();
        $output = ob_get_clean();

        static::assertSame('', $output);
    }

    /**
     * Test that the loop breaks on the second abort check within an
     * iteration. The first and second checks pass, allowing the callback
     * to run, then the third check (second per-iteration check) triggers
     * the break.
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

        static::assertSame(1, $callCount);
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
        $this->travelTo(now());

        $polls  = 0;
        $aborts = 0;

        // The client never aborts; a high backstop prevents a runaway loop if
        // the cap regresses, so the assertion fails cleanly rather than hanging.
        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 1000 ? 1 : 0;
        });

        $stream   = new EventStream(60, 5);
        $response = $stream->toResponse(function () use (&$polls): void {
            $polls++;
            $this->travel(1)->seconds();
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        // 5s ceiling at 1s per poll: the loop exits on the fifth poll.
        static::assertSame(5, $polls);
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

        static::assertSame(3, $polls);
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
        $this->travelTo(now());

        $polls  = 0;
        $aborts = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 1000 ? 1 : 0;
        });

        $stream = new class (60, 5) extends EventStream {
            /** @var int */
            public int $endCount = 0;

            /** @var \SineMacula\Sse\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \SineMacula\Sse\StreamTerminationReason  $reason
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
            $this->travel(1)->seconds();
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertSame(1, $stream->endCount);
        static::assertSame(StreamTerminationReason::MaxDuration, $stream->endReason);
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
            /** @var \SineMacula\Sse\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \SineMacula\Sse\StreamTerminationReason  $reason
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

        static::assertSame(StreamTerminationReason::MaxIterations, $stream->endReason);
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
            /** @var \SineMacula\Sse\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \SineMacula\Sse\StreamTerminationReason  $reason
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

        static::assertSame(StreamTerminationReason::ClientDisconnect, $stream->endReason);
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
            /** @var \SineMacula\Sse\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \SineMacula\Sse\StreamTerminationReason  $reason
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

        static::assertSame(StreamTerminationReason::Error, $stream->endReason);
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
        $this->travelTo(now());

        $polls  = 0;
        $aborts = 0;

        // The client eventually disconnects; nothing else may end the stream.
        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 50 ? 1 : 0;
        });

        $stream = new class extends EventStream {
            /** @var \SineMacula\Sse\StreamTerminationReason|null */
            public ?StreamTerminationReason $endReason = null;

            /**
             * @param  \SineMacula\Sse\StreamTerminationReason  $reason
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
            $this->travel(3600)->seconds();
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        // The clock advanced thousands of seconds per poll yet the stream ran
        // until the client disconnected - it did not self-terminate.
        static::assertGreaterThan(0, $polls);
        static::assertSame(StreamTerminationReason::ClientDisconnect, $stream->endReason);
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
        $this->travelTo(now());

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
            $this->travel(1)->seconds();
        });

        ob_start();
        $response->sendContent();
        ob_get_clean();

        static::assertSame(5, $polls);
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

        static::assertDirectoryDoesNotExist($root . '/config');

        $providers = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
            if (str_ends_with($file->getFilename(), 'ServiceProvider.php')) {
                $providers[] = $file->getPathname();
            }
        }

        static::assertSame([], $providers);
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

        static::assertSame(200, $response->getStatusCode());
    }

    /**
     * Test that the heartbeat timestamp is reset after a comment fires, so a
     * heartbeat does not repeat on every subsequent sub-interval iteration.
     *
     * @return void
     */
    public function testHeartbeatTimestampResetsBetweenIntervals(): void
    {
        $this->travelTo(now());

        $aborts = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$aborts): int {
            return ++$aborts >= 25 ? 1 : 0;
        });

        // Each poll advances less than the 10s interval, so without the
        // post-heartbeat timestamp reset the heartbeat would fire on every poll
        // once the first interval elapses, inflating the comment count.
        $stream   = new EventStream(heartbeatInterval: 10);
        $response = $stream->toResponse(function (): void {
            $this->travel(4)->seconds();
        });

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $commentCount = substr_count((string) $output, self::SSE_COMMENT);

        // One initial keep-alive plus a heartbeat roughly every third poll - far
        // fewer than the one-per-poll a missing reset would produce.
        static::assertGreaterThanOrEqual(2, $commentCount);
        static::assertLessThanOrEqual(5, $commentCount);
    }
}
