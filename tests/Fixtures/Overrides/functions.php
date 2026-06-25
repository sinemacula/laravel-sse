<?php

declare(strict_types = 1);

namespace SineMacula\Sse;

use Tests\Fixtures\Support\FunctionOverrides;

/**
 * Override connection_aborted() within the Sse namespace.
 *
 * @SuppressWarnings("php:S100")
 *
 * @return int
 */
function connection_aborted(): int
{
    $override = FunctionOverrides::get('connection_aborted');

    if ($override !== null) {
        // @phpstan-ignore cast.int
        return (int) $override();
    }

    return \connection_aborted();
}

/**
 * Override sleep() within the Sse namespace.
 *
 * @param  int  $seconds
 * @return int
 */
function sleep(int $seconds): int
{
    $override = FunctionOverrides::get('sleep');

    if ($override !== null) {
        // @phpstan-ignore cast.int
        return (int) $override($seconds);
    }

    return \sleep($seconds);
}

/**
 * Override flush() within the Sse namespace.
 *
 * @return void
 */
function flush(): void
{
    $override = FunctionOverrides::get('flush');

    if ($override !== null) {
        $override();
        return;
    }

    \flush();
}

/**
 * Override ob_flush() within the Sse namespace.
 *
 * @SuppressWarnings("php:S100")
 *
 * @return void
 */
function ob_flush(): void
{
    $override = FunctionOverrides::get('ob_flush');

    if ($override !== null) {
        $override();
        return;
    }

    \ob_flush();
}

/**
 * Override ob_get_status() within the Sse namespace.
 *
 * @SuppressWarnings("php:S100")
 *
 * @return array<string, int>
 */
function ob_get_status(): array
{
    $override = FunctionOverrides::get('ob_get_status');

    if ($override !== null) {
        // @phpstan-ignore return.type
        return $override();
    }

    // @phpstan-ignore return.type
    return \ob_get_status();
}

/**
 * Override hrtime() within the Sse namespace.
 *
 * @SuppressWarnings("php:S100")
 *
 * @param  bool  $asNumber
 * @return int
 */
function hrtime(bool $asNumber = true): int
{
    $override = FunctionOverrides::get('hrtime');

    if ($override !== null) {
        // @phpstan-ignore cast.int
        return (int) $override($asNumber);
    }

    return \hrtime(true);
}
