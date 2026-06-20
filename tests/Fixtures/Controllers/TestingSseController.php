<?php

namespace Tests\Fixtures\Controllers;

use SineMacula\Sse\Concerns\RespondsWithEventStream;
use SineMacula\Sse\Emitter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fixture controller exposing an SSE endpoint.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class TestingSseController
{
    use RespondsWithEventStream;

    /**
     * Stream a single update event over SSE.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function stream(): StreamedResponse
    {
        return $this->respondWithEventStream(function (Emitter $emitter): void {
            $emitter->emit(['tick' => 1], 'update');
        });
    }
}
