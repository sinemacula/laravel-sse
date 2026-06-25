<?php

declare(strict_types = 1);

namespace SineMacula\Sse;

/**
 * Structured SSE event emitter.
 *
 * Provides a clean API for emitting SSE events and comments without callers
 * needing to construct wire-format strings. Each method writes the appropriate
 * SSE-formatted output and flushes immediately.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Emitter
{
    /**
     * Emit an SSE event.
     *
     * When `$event` is non-null, an `event:` field is written before the data
     * lines. Array data is JSON-encoded into a single string. String data is
     * split on newlines, with each line emitted as a separate `data:` field per
     * the SSE specification.
     *
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint
     *
     * @param  array<mixed>|string  $data
     * @param  string|null  $event
     * @return void
     *
     * @throws \JsonException
     */
    public function emit(array|string $data, ?string $event = null): void
    {
        if ($event !== null) {
            $event = str_replace(["\r", "\n"], '', $event);

            echo "event: {$event}\n";
        }

        if (is_array($data)) {
            $data = json_encode($data, JSON_THROW_ON_ERROR);
        }

        foreach ((array) preg_split('/\r\n|\r|\n/', $data) as $line) {
            echo "data: {$line}\n";
        }

        echo "\n";

        flush();
    }

    /**
     * Emit an SSE comment line.
     *
     * Comments are typically used for keep-alive signals to prevent proxy or
     * load balancer timeouts on idle connections.
     *
     * @param  string  $text
     * @return void
     */
    public function comment(string $text = ''): void
    {
        $text = str_replace(["\r", "\n"], '', $text);

        echo ":{$text}\n\n";

        flush();
    }
}
