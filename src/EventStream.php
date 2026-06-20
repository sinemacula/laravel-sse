<?php

namespace SineMacula\Sse;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE transport lifecycle manager.
 *
 * Owns response construction, the polling loop, heartbeat emission,
 * connection-abort detection, and error handling for Server-Sent Event
 * streams. Designed for subclass extension via protected hooks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class EventStream
{
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
     */
    public function __construct(

        /** The heartbeat interval in seconds for keep-alive comments. */
        private readonly int $heartbeatInterval = 20,

        /** The maximum stream duration in seconds (0 = unbounded). */
        private readonly int $maxDuration = 0,

        /** The maximum number of poll iterations (0 = unbounded). */
        private readonly int $maxIterations = 0,

    ) {}

    /**
     * Build an SSE streamed response.
     *
     * Constructs a StreamedResponse with the required SSE headers and a
     * streaming closure that runs the polling loop. Callback arity is
     * detected via reflection to determine whether the emitter is passed.
     *
     * @param  callable(): void|callable(\SineMacula\Sse\Emitter): void  $callback
     * @param  int  $interval
     * @param  int  $status
     * @param  array<string, string>  $headers
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function toResponse(callable $callback, int $interval = 1, int $status = 200, array $headers = []): StreamedResponse
    {
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
     * Reports the exception and emits an error event to the client.
     * Returns false to break the polling loop, or true to continue.
     * Subclasses may override this to implement recovery strategies.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  \Throwable  $exception
     * @param  \SineMacula\Sse\Emitter  $emitter
     * @return bool
     */
    protected function handleStreamError(\Throwable $exception, Emitter $emitter): bool
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
     * cleanup such as releasing resources or logging, and may inspect
     * `$reason` to distinguish a graceful duration/iteration ceiling from a
     * client disconnect or an unrecoverable error.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  \SineMacula\Sse\StreamTerminationReason  $reason
     * @return void
     */
    protected function onStreamEnd(StreamTerminationReason $reason): void {}

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

        $streamStart        = now();
        $heartbeatTimestamp = now();
        $iterations         = 0;
        $reason             = StreamTerminationReason::ClientDisconnect;

        while (true) {

            if (connection_aborted()) {
                $reason = StreamTerminationReason::ClientDisconnect;
                break;
            }

            try {
                $acceptsEmitter ? $callback($emitter) : $callback();
            } catch (\Throwable $exception) {
                if (!$this->handleStreamError($exception, $emitter)) {
                    $reason = StreamTerminationReason::Error;
                    break;
                }

                continue;
            }

            $this->flushOutput();

            $heartbeatTimestamp = $this->emitHeartbeatIfDue($emitter, $heartbeatTimestamp);

            $iterations++;

            $ceiling = $this->ceilingReason($streamStart, $iterations);

            if ($ceiling !== null) {
                $reason = $ceiling;
                break;
            }

            // @phpstan-ignore-next-line if.alwaysFalse (connection state may change between the two checks per iteration)
            if (connection_aborted()) {
                $reason = StreamTerminationReason::ClientDisconnect;
                break;
            }

            sleep($interval);
        }

        $this->onStreamEnd($reason);
    }

    /**
     * Resolve the termination reason when a configured ceiling has been
     * reached, or null when neither ceiling applies.
     *
     * @param  \Carbon\CarbonInterface  $streamStart
     * @param  int  $iterations
     * @return \SineMacula\Sse\StreamTerminationReason|null
     */
    private function ceilingReason(\Carbon\CarbonInterface $streamStart, int $iterations): ?StreamTerminationReason
    {
        if ($this->maxDuration > 0 && $streamStart->diffInSeconds(now()) >= $this->maxDuration) {
            return StreamTerminationReason::MaxDuration;
        }

        if ($this->maxIterations > 0 && $iterations >= $this->maxIterations) {
            return StreamTerminationReason::MaxIterations;
        }

        return null;
    }

    /**
     * Emit a heartbeat comment when the configured interval has elapsed,
     * returning the timestamp the next interval is measured from.
     *
     * @param  \SineMacula\Sse\Emitter  $emitter
     * @param  \Carbon\CarbonInterface  $heartbeatTimestamp
     * @return \Carbon\CarbonInterface
     */
    private function emitHeartbeatIfDue(Emitter $emitter, \Carbon\CarbonInterface $heartbeatTimestamp): \Carbon\CarbonInterface
    {
        if ($heartbeatTimestamp->diffInSeconds(now()) >= $this->heartbeatInterval) {
            $emitter->comment();

            return now();
        }

        return $heartbeatTimestamp;
    }

    /**
     * Flush any active output buffers and the system output buffer.
     *
     * @return void
     */
    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
