<?php

namespace Tests\Fixtures;

use SineMacula\Sse\Concerns\RespondsWithEventStream;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inheritable harness for exercising the RespondsWithEventStream trait.
 *
 * Test subclasses extend this base rather than using the trait directly, so the
 * trait's protected seam methods are crossed at a real inheritance boundary. A
 * protected-to-private mutation on any seam then becomes observable: a private
 * trait method is neither overridable by, nor callable from, a subclass.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class RespondsWithEventStreamHarness
{
    use RespondsWithEventStream;

    /**
     * Open a stream using the trait's default heartbeat, ceilings, and polling
     * interval.
     *
     * @param  callable(): void|callable(\SineMacula\Sse\Emitter): void  $callback
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function stream(callable $callback): StreamedResponse
    {
        return $this->respondWithEventStream($callback);
    }
}
