<?php

namespace SineMacula\Sse;

/**
 * The reason an event stream's polling loop terminated.
 *
 * Passed to {@see EventStream::onStreamEnd()} so a subclass or operator can
 * distinguish a graceful ceiling-reached close (a configured duration or
 * iteration cap) from a client disconnect or an unrecoverable error.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum StreamTerminationReason: string
{
    /** The client closed the connection. */
    case ClientDisconnect = 'client_disconnect';

    /** An unrecoverable error broke the loop. */
    case Error = 'error';

    /** The configured maximum stream duration was reached. */
    case MaxDuration = 'max_duration';

    /** The configured maximum poll-iteration count was reached. */
    case MaxIterations = 'max_iterations';
}
