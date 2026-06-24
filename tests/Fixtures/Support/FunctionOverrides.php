<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

// The override registry holds arbitrary callables, so its callable type
// signatures are necessarily mixed; phpstan requires the signature while the
// standard forbids mixed, so relax the latter for this generic registry.
// phpcs:disable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint

/**
 * Static registry for namespace-scoped PHP function overrides.
 *
 * Test code sets callbacks here; namespace-level function stubs in
 * Overrides/functions.php delegate to these callbacks, falling back to the real
 * built-in when no override is active.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FunctionOverrides
{
    /** @var array<string, (callable(mixed...): mixed)|null> */
    private static array $overrides = []; // @phpstan-ignore sineMacula.mutableStaticProperty (test-only registry read by namespace function stubs)

    /**
     * Register an override for the given function name.
     *
     * @param  string  $name
     * @param  callable|null  $callback
     *
     * @phpstan-param (callable(mixed...): mixed)|null $callback
     *
     * @return void
     */
    public static function set(string $name, ?callable $callback): void
    {
        self::$overrides[$name] = $callback;
    }

    /**
     * Get the current override for the given function name.
     *
     * @param  string  $name
     * @return callable|null
     *
     * @phpstan-return (callable(mixed...): mixed)|null
     */
    public static function get(string $name): ?callable
    {
        return self::$overrides[$name] ?? null;
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
