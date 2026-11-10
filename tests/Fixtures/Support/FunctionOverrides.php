<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

/**
 * Static registry for namespace-scoped PHP function overrides.
 *
 * Test code sets callbacks here; namespace-level function stubs in
 * Overrides/functions.php delegate to these callbacks, falling back to the real
 * built-in when no override is active.
 *
 * @phpstan-type AbortProbe callable(): int
 * @phpstan-type Sleeper callable(int): int
 * @phpstan-type Flusher callable(): void
 * @phpstan-type StatusReader callable(): array<string, int>
 * @phpstan-type Clock callable(bool): int
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FunctionOverrides
{
    /** @var array{connection_aborted?: AbortProbe, sleep?: Sleeper, flush?: Flusher, ob_flush?: Flusher, ob_get_status?: StatusReader, hrtime?: Clock} */
    private static array $overrides = []; // @phpstan-ignore sineMacula.mutableStaticProperty (test-only registry read by namespace function stubs)

    /**
     * Override connection_aborted() within the Sse namespace.
     *
     * @param  callable  $callback
     *
     * @phpstan-param AbortProbe $callback
     *
     * @return void
     */
    public static function setConnectionAborted(callable $callback): void
    {
        self::$overrides['connection_aborted'] = $callback;
    }

    /**
     * Override sleep() within the Sse namespace.
     *
     * @param  callable  $callback
     *
     * @phpstan-param Sleeper $callback
     *
     * @return void
     */
    public static function setSleep(callable $callback): void
    {
        self::$overrides['sleep'] = $callback;
    }

    /**
     * Override flush() within the Sse namespace.
     *
     * @param  callable  $callback
     *
     * @phpstan-param Flusher $callback
     *
     * @return void
     */
    public static function setFlush(callable $callback): void
    {
        self::$overrides['flush'] = $callback;
    }

    /**
     * Override ob_flush() within the Sse namespace.
     *
     * @param  callable  $callback
     *
     * @phpstan-param Flusher $callback
     *
     * @return void
     */
    public static function setObFlush(callable $callback): void
    {
        self::$overrides['ob_flush'] = $callback;
    }

    /**
     * Override ob_get_status() within the Sse namespace.
     *
     * @param  callable  $callback
     *
     * @phpstan-param StatusReader $callback
     *
     * @return void
     */
    public static function setObGetStatus(callable $callback): void
    {
        self::$overrides['ob_get_status'] = $callback;
    }

    /**
     * Override hrtime() within the Sse namespace.
     *
     * @param  callable  $callback
     *
     * @phpstan-param Clock $callback
     *
     * @return void
     */
    public static function setHrtime(callable $callback): void
    {
        self::$overrides['hrtime'] = $callback;
    }

    /**
     * Get the current connection_aborted() override.
     *
     * @return callable|null
     *
     * @phpstan-return AbortProbe|null
     */
    public static function getConnectionAborted(): ?callable
    {
        return self::$overrides['connection_aborted'] ?? null;
    }

    /**
     * Get the current sleep() override.
     *
     * @return callable|null
     *
     * @phpstan-return Sleeper|null
     */
    public static function getSleep(): ?callable
    {
        return self::$overrides['sleep'] ?? null;
    }

    /**
     * Get the current flush() override.
     *
     * @return callable|null
     *
     * @phpstan-return Flusher|null
     */
    public static function getFlush(): ?callable
    {
        return self::$overrides['flush'] ?? null;
    }

    /**
     * Get the current ob_flush() override.
     *
     * @return callable|null
     *
     * @phpstan-return Flusher|null
     */
    public static function getObFlush(): ?callable
    {
        return self::$overrides['ob_flush'] ?? null;
    }

    /**
     * Get the current ob_get_status() override.
     *
     * @return callable|null
     *
     * @phpstan-return StatusReader|null
     */
    public static function getObGetStatus(): ?callable
    {
        return self::$overrides['ob_get_status'] ?? null;
    }

    /**
     * Get the current hrtime() override.
     *
     * @return callable|null
     *
     * @phpstan-return Clock|null
     */
    public static function getHrtime(): ?callable
    {
        return self::$overrides['hrtime'] ?? null;
    }

    /**
     * Reset all overrides.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$overrides = [];
    }
}
