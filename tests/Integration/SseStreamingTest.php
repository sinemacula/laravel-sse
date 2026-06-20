<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Sse\EventStream;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\Controllers\TestingSseController;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

/**
 * End-to-end tests covering SSE delivery over an actual HTTP response.
 *
 * A real HTTP request resolves a controller route that returns the
 * EventStream-based streamed response; the test asserts the negotiated
 * status, the SSE transport headers, and the streamed wire-format
 * content captured from the response body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EventStream::class)]
class SseStreamingTest extends TestCase
{
    /** @var string The SSE endpoint under test. */
    private const string SSE_URI = '/events';

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Route::get(self::SSE_URI, [TestingSseController::class, 'stream']);

        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);
        FunctionOverrides::set('ob_get_level', fn () => 0);
        FunctionOverrides::set('sleep', fn (int $_s) => 0);

        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 2 ? 1 : 0;
        });
    }

    /**
     * Test that the SSE route responds with a streamed response carrying
     * the SSE transport headers.
     *
     * @return void
     */
    public function testSseRouteRespondsWithSseHeaders(): void
    {
        $response = $this->get(self::SSE_URI);

        $response->assertOk();

        static::assertInstanceOf(StreamedResponse::class, $response->baseResponse);

        $content_type = (string) $response->baseResponse->headers->get('Content-Type');

        static::assertStringStartsWith('text/event-stream', $content_type);

        $cache_control = (string) $response->baseResponse->headers->get('Cache-Control');

        static::assertStringContainsString('no-cache', $cache_control);
        static::assertStringContainsString('no-transform', $cache_control);
        static::assertSame('keep-alive', $response->baseResponse->headers->get('Connection'));
        static::assertSame('no', $response->baseResponse->headers->get('X-Accel-Buffering'));
    }

    /**
     * Test that the streamed body delivers the SSE wire format: the initial
     * keep-alive comment followed by the event emitted by the controller
     * callback.
     *
     * @return void
     */
    public function testSseRouteStreamsEventWireFormat(): void
    {
        $response = $this->get(self::SSE_URI);

        $response->assertOk();

        $content = $response->streamedContent();

        static::assertSame(":\n\nevent: update\ndata: {\"tick\":1}\n\n", $content);
    }
}
