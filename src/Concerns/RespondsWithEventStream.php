<?php

declare(strict_types = 1);

namespace SineMacula\Sse\Concerns;

use SineMacula\Sse\EventStream;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller helper for returning SSE event-stream responses.
 *
 * Mix this trait into any controller to gain `respondWithEventStream()`. It is
 * intentionally decoupled from the API toolkit base controller: the HTTP status
 * is accepted as a plain integer so callers are not forced to depend on any
 * particular enum library.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait RespondsWithEventStream
{
    /**
     * The SSE heartbeat interval in seconds.
     *
     * Override to change the keep-alive cadence.
     *
     * @return int
     */
    protected function heartbeatInterval(): int
    {
        return 20;
    }

    /**
     * The maximum stream duration in seconds (0 = unbounded).
     *
     * Override to bound the stream so it self-terminates and releases its
     * worker even when the client never disconnects - recommended when serving
     * SSE under Laravel Octane.
     *
     * @return int
     */
    protected function maxStreamDuration(): int
    {
        return 0;
    }

    /**
     * The maximum number of poll iterations (0 = unbounded).
     *
     * Override as an optional secondary guard alongside
     * {@see maxStreamDuration()}.
     *
     * @return int
     */
    protected function maxStreamIterations(): int
    {
        return 0;
    }

    /**
     * Respond with an SSE event stream.
     *
     * Delegates to EventStream for response construction, the polling loop,
     * heartbeat emission, and error handling. Override
     * {@see maxStreamDuration()} or {@see maxStreamIterations()} to bound the
     * stream so it self-terminates and releases its worker.
     *
     * @param  callable(): void|callable(\SineMacula\Sse\Emitter): void  $callback
     * @param  int  $interval
     * @param  int  $status
     * @param  array<string, string>  $headers
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function respondWithEventStream(callable $callback, int $interval = 1, int $status = 200, array $headers = []): StreamedResponse
    {
        return (new EventStream($this->heartbeatInterval(), $this->maxStreamDuration(), $this->maxStreamIterations()))
            ->toResponse($callback, $interval, $status, $headers);
    }
}
