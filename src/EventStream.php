<?php

declare(strict_types = 1);

namespace SineMacula\Sse;

use SineMacula\Sse\Enums\StreamTerminationReason;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE transport lifecycle manager.
 *
 * Owns response construction, the polling loop, heartbeat emission,
 * connection-abort detection, and error handling for Server-Sent Event streams.
 * Designed for subclass extension via protected hooks.
 *
 * @inheritable
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class EventStream
{
    /** The number of nanoseconds in one second, for hrtime() arithmetic. */
    private const int NANOSECONDS_PER_SECOND = 1000000000;

    /**
     * Create a new event stream instance.
     *
     * A non-zero `$maxDuration` or `$maxIterations` makes the stream
     * self-terminate gracefully once that ceiling is reached, releasing the
     * worker even if the client never disconnects. Both default to `0`
     * (unbounded), preserving the run-until-disconnect behaviour.
     *
     * @param  int  $heartbeatInterval
     * @param  int  $maxDuration
     * @param  int  $maxIterations
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(

        /** The heartbeat interval in seconds for keep-alive comments. */
        private readonly int $heartbeatInterval = 20,

        /** The maximum stream duration in seconds (0 = unbounded). */
        private readonly int $maxDuration = 0,

        /** The maximum number of poll iterations (0 = unbounded). */
        private readonly int $maxIterations = 0,
    ) {
        if ($heartbeatInterval < 0) {
            throw new \InvalidArgumentException('The heartbeat interval must not be negative.');
        }

        if ($maxDuration < 0) {
            throw new \InvalidArgumentException('The maximum duration must not be negative.');
        }

        if ($maxIterations < 0) {
            throw new \InvalidArgumentException('The maximum iterations must not be negative.');
        }
    }

    /**
     * Build an SSE streamed response.
     *
     * Constructs a StreamedResponse with the required SSE headers and a
     * streaming closure that runs the polling loop.
     *
     * Callback arity is detected via reflection: the emitter is passed only
     * when the callback declares at least one parameter. Detection is purely
     * by parameter count, so a callback whose first parameter is optional or
     * variadic still receives the emitter, while a zero-parameter callback
     * never does.
     *
     * @param  callable(): void|callable(\SineMacula\Sse\Emitter): void  $callback
     * @param  int  $interval
     * @param  int  $status
     * @param  array<string, string>  $headers
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     *
     * @throws \InvalidArgumentException
     */
    public function toResponse(
        callable $callback,
        int $interval = 1,
        int $status = 200,
        array $headers = [],
    ): StreamedResponse {
        if ($interval < 0) {
            throw new \InvalidArgumentException('The polling interval must not be negative.');
        }

        $headers = array_merge($headers, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);

        $acceptsEmitter = (new \ReflectionFunction(\Closure::fromCallable($callback)))->getNumberOfParameters() >= 1;
        $emitter        = new Emitter;

        return new StreamedResponse(function () use ($callback, $interval, $emitter, $acceptsEmitter): void {
            $this->runEventStream($callback, $interval, $emitter, $acceptsEmitter);
        }, $status, $headers);
    }

    /**
     * Handle a stream error.
     *
     * Reports the exception and emits an error event to the client. Returns
     * false to break the polling loop, or true to continue. Subclasses may
     * override this to implement recovery strategies.
     *
     * Note: overriding this method REPLACES its side effects entirely. The
     * `report($exception)` call and the generic `error` event sent to the
     * client both live here, so an override that omits them silently drops
     * error reporting and client notification. Call the parent implementation
     * (or re-emit) to retain them.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  \Throwable  $exception
     * @param  \SineMacula\Sse\Emitter  $emitter
     * @return bool
     */
    protected function shouldContinueAfterError(\Throwable $exception, Emitter $emitter): bool
    {
        report($exception);
        $emitter->emit('An error occurred', 'error');

        return false;
    }

    /**
     * Called once before the polling loop begins.
     *
     * The default implementation emits an initial keep-alive comment.
     * Subclasses may override to perform custom initialisation.
     *
     * @param  \SineMacula\Sse\Emitter  $emitter
     * @return void
     */
    protected function onStreamStart(Emitter $emitter): void
    {
        $emitter->comment();
    }

    /**
     * Called after the polling loop exits.
     *
     * The default implementation is empty. Subclasses may override to perform
     * cleanup such as releasing resources or logging, and may inspect `$reason`
     * to distinguish a graceful duration/iteration ceiling from a client
     * disconnect or an unrecoverable error.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
     *
     * @param  \SineMacula\Sse\Enums\StreamTerminationReason  $reason
     * @return void
     */
    protected function onStreamEnd(StreamTerminationReason $reason): void {}

    // The poll loop coordinates several exit conditions in one place; its
    // cognitive complexity is inherent to that and covered by tests.
    // phpcs:disable SlevomatCodingStandard.Complexity.Cognitive
    /**
     * Execute the SSE polling loop.
     *
     * Emits an initial keep-alive comment, then polls the callback on each
     * iteration, sending a heartbeat comment when the configured interval
     * elapses. Exits when the client disconnects or an unrecoverable error
     * occurs.
     *
     * @param  callable(): void|callable(\SineMacula\Sse\Emitter): void  $callback
     * @param  int  $interval
     * @param  \SineMacula\Sse\Emitter  $emitter
     * @param  bool  $acceptsEmitter
     * @return void
     */
    private function runEventStream(callable $callback, int $interval, Emitter $emitter, bool $acceptsEmitter): void
    {
        $this->onStreamStart($emitter);

        $streamStart = hrtime(true);
        $heartbeatAt = hrtime(true);
        $iterations  = 0;
        $reason      = StreamTerminationReason::CLIENT_DISCONNECT;

        while (true) {

            $premature = $this->preemptiveTerminationReason($streamStart);

            if ($premature !== null) {
                $reason = $premature;
                break;
            }

            try {
                $acceptsEmitter ? $callback($emitter) : $callback();

                $this->flushOutput();

                $heartbeatAt = $this->emitHeartbeatIfDue($emitter, $heartbeatAt);
            } catch (\Throwable $exception) {
                if (!$this->shouldContinueAfterError($exception, $emitter)) {
                    $reason = StreamTerminationReason::ERROR;
                    break;
                }
            }

            $iterations++;

            $postPoll = $this->postPollTerminationReason($iterations);

            if ($postPoll !== null) {
                $reason = $postPoll;
                break;
            }

            sleep($interval);
        }

        $this->onStreamEnd($reason);
    }
    // phpcs:enable SlevomatCodingStandard.Complexity.Cognitive

    /**
     * Resolve the termination reason that applies before a poll runs: a client
     * disconnect, or the maximum duration having elapsed.
     *
     * The duration ceiling is checked here, before the callback, so the stream
     * never runs a poll once the deadline has passed. Elapsed time is measured
     * against the monotonic clock (`hrtime()`) so a backward wall-clock step
     * (NTP or VM correction) cannot stall the stream or overshoot the deadline.
     *
     * @param  int  $streamStart
     * @return \SineMacula\Sse\Enums\StreamTerminationReason|null
     */
    private function preemptiveTerminationReason(int $streamStart): ?StreamTerminationReason
    {
        if (connection_aborted()) {
            return StreamTerminationReason::CLIENT_DISCONNECT;
        }

        if ($this->maxDuration > 0 && hrtime(true) - $streamStart >= $this->maxDuration * self::NANOSECONDS_PER_SECOND) {
            return StreamTerminationReason::MAX_DURATION;
        }

        return null;
    }

    /**
     * Resolve the termination reason that applies after a poll completes: the
     * iteration ceiling being reached, or a client disconnect detected on the
     * second per-iteration check.
     *
     * @param  int  $iterations
     * @return \SineMacula\Sse\Enums\StreamTerminationReason|null
     */
    private function postPollTerminationReason(int $iterations): ?StreamTerminationReason
    {
        if ($this->maxIterations > 0 && $iterations >= $this->maxIterations) {
            return StreamTerminationReason::MAX_ITERATIONS;
        }

        if (connection_aborted()) {
            return StreamTerminationReason::CLIENT_DISCONNECT;
        }

        return null;
    }

    /**
     * Emit a heartbeat comment when the configured interval has elapsed,
     * returning the monotonic timestamp the next interval is measured from.
     *
     * @param  \SineMacula\Sse\Emitter  $emitter
     * @param  int  $heartbeatAt
     * @return int
     */
    private function emitHeartbeatIfDue(Emitter $emitter, int $heartbeatAt): int
    {
        if (hrtime(true) - $heartbeatAt >= $this->heartbeatInterval * self::NANOSECONDS_PER_SECOND) {
            $emitter->comment();

            return hrtime(true);
        }

        return $heartbeatAt;
    }

    /**
     * Flush the active output buffer and the system output buffer.
     *
     * A non-zero buffer level proves a buffer exists but not that it can be
     * flushed, so the `PHP_OUTPUT_HANDLER_FLUSHABLE` flag is checked before
     * calling `ob_flush()` to avoid a warning on a non-flushable handler.
     *
     * @return void
     */
    private function flushOutput(): void
    {
        $status = ob_get_status();

        if (isset($status['flags']) && ($status['flags'] & PHP_OUTPUT_HANDLER_FLUSHABLE) === PHP_OUTPUT_HANDLER_FLUSHABLE) {
            ob_flush();
        }

        flush();
    }
}
