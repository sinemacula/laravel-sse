<?php

declare(strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Sse\Emitter;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

/**
 * Tests for the SSE Emitter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Emitter::class)]
final class EmitterTest extends TestCase
{
    /** @var \SineMacula\Sse\Emitter The emitter instance under test. */
    private Emitter $emitter;

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

        $this->emitter = new Emitter;
    }

    /**
     * Test that emit writes single-line data with a blank-line terminator.
     *
     * @return void
     */
    public function testEmitWritesSingleLineDataWithTerminator(): void
    {
        ob_start();
        $this->emitter->emit('hello');
        $output = ob_get_clean();

        self::assertSame("data: hello\n\n", $output);
    }

    /**
     * Test that emit writes a named event field before the data.
     *
     * @return void
     */
    public function testEmitWritesNamedEventBeforeData(): void
    {
        ob_start();
        $this->emitter->emit('hello', 'greeting');
        $output = ob_get_clean();

        self::assertSame("event: greeting\ndata: hello\n\n", $output);
    }

    /**
     * Test that emit splits multiline data into separate data lines.
     *
     * @return void
     */
    public function testEmitSplitsMultilineDataIntoSeparateDataLines(): void
    {
        ob_start();
        $this->emitter->emit("line1\nline2");
        $output = ob_get_clean();

        self::assertSame("data: line1\ndata: line2\n\n", $output);
    }

    /**
     * Test that emit JSON-encodes array data.
     *
     * @return void
     */
    public function testEmitJsonEncodesArrayData(): void
    {
        ob_start();
        $this->emitter->emit(['key' => 'value']);
        $output = ob_get_clean();

        self::assertSame("data: {\"key\":\"value\"}\n\n", $output);
    }

    /**
     * Test that emit JSON-encodes array data with a named event.
     *
     * @return void
     */
    public function testEmitJsonEncodesArrayDataWithNamedEvent(): void
    {
        ob_start();
        $this->emitter->emit(['k' => 'v'], 'update');
        $output = ob_get_clean();

        self::assertSame("event: update\ndata: {\"k\":\"v\"}\n\n", $output);
    }

    /**
     * Test that comment writes an empty comment line.
     *
     * @return void
     */
    public function testCommentWritesEmptyCommentLine(): void
    {
        ob_start();
        $this->emitter->comment();
        $output = ob_get_clean();

        self::assertSame(":\n\n", $output);
    }

    /**
     * Test that comment writes a text comment line.
     *
     * @return void
     */
    public function testCommentWritesTextCommentLine(): void
    {
        ob_start();
        $this->emitter->comment(' keep-alive');
        $output = ob_get_clean();

        self::assertSame(": keep-alive\n\n", $output);
    }

    /**
     * Test that emit splits carriage-return data into separate data lines.
     *
     * @return void
     */
    public function testEmitSplitsCarriageReturnDataIntoSeparateDataLines(): void
    {
        ob_start();
        $this->emitter->emit("line1\rline2\rline3");
        $output = ob_get_clean();

        self::assertSame("data: line1\ndata: line2\ndata: line3\n\n", $output);
    }

    /**
     * Test that emit splits CRLF data into separate data lines.
     *
     * @return void
     */
    public function testEmitSplitsCrlfDataIntoSeparateDataLines(): void
    {
        ob_start();
        $this->emitter->emit("line1\r\nline2\r\nline3");
        $output = ob_get_clean();

        self::assertSame("data: line1\ndata: line2\ndata: line3\n\n", $output);
    }

    /**
     * Test that emit splits mixed line endings into separate data lines.
     *
     * @return void
     */
    public function testEmitSplitsMixedLineEndingsIntoSeparateDataLines(): void
    {
        ob_start();
        $this->emitter->emit("a\r\nb\nc\rd");
        $output = ob_get_clean();

        self::assertSame("data: a\ndata: b\ndata: c\ndata: d\n\n", $output);
    }

    /**
     * Test that emit handles an empty string as an empty data line.
     *
     * @return void
     */
    public function testEmitHandlesEmptyStringAsEmptyDataLine(): void
    {
        ob_start();
        $this->emitter->emit('');
        $output = ob_get_clean();

        self::assertSame("data: \n\n", $output);
    }

    /**
     * Test that emit splits consecutive newlines into empty data lines.
     *
     * @return void
     */
    public function testEmitSplitsConsecutiveNewlinesIntoEmptyDataLines(): void
    {
        ob_start();
        $this->emitter->emit("\n\n");
        $output = ob_get_clean();

        self::assertSame("data: \ndata: \ndata: \n\n", $output);
    }

    /**
     * Test that emit transmits embedded SSE field prefixes as data.
     *
     * @return void
     */
    public function testEmitTransmitsEmbeddedSseFieldPrefixesAsData(): void
    {
        ob_start();
        $this->emitter->emit("data: fake\nevent: x");
        $output = ob_get_clean();

        self::assertSame("data: data: fake\ndata: event: x\n\n", $output);
    }

    /**
     * Test that emit calls flush after writing.
     *
     * @return void
     */
    public function testEmitCallsFlush(): void
    {
        $flushCalled = false;

        FunctionOverrides::set('flush', function () use (&$flushCalled): void {
            $flushCalled = true;
        });

        ob_start();
        $this->emitter->emit('test');
        ob_get_clean();

        self::assertTrue($flushCalled);
    }

    /**
     * Test that comment calls flush after writing.
     *
     * @return void
     */
    public function testCommentCallsFlush(): void
    {
        $flushCalled = false;

        FunctionOverrides::set('flush', function () use (&$flushCalled): void {
            $flushCalled = true;
        });

        ob_start();
        $this->emitter->comment();
        ob_get_clean();

        self::assertTrue($flushCalled);
    }
}
