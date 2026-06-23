<?php

declare(strict_types = 1);

namespace SineMacula\Sse\Enums;

/**
 * The reason an event stream's polling loop terminated.
 *
 * Passed to {@see \SineMacula\Sse\EventStream::onStreamEnd()} so a subclass or
 * operator can distinguish a graceful ceiling-reached close (a configured
 * duration or iteration cap) from a client disconnect or an unrecoverable
 * error.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum StreamTerminationReason: string
{
    // The client closed the connection.
    case CLIENT_DISCONNECT = 'client_disconnect';

    // An unrecoverable error broke the loop.
    case ERROR = 'error';

    // The configured maximum stream duration was reached.
    case MAX_DURATION = 'max_duration';

    // The configured maximum poll-iteration count was reached.
    case MAX_ITERATIONS = 'max_iterations';
}
