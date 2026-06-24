# Laravel SSE

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-sse.svg)](https://packagist.org/packages/sinemacula/laravel-sse)
[![Build Status](https://github.com/sinemacula/laravel-sse/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-sse/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-sse/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-sse/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-sse/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-sse)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-sse/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-sse)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-sse.svg)](https://packagist.org/packages/sinemacula/laravel-sse)

Server-Sent Events (SSE) streaming primitives for Laravel - an event stream response built around a heartbeat polling
loop and a typed emitter. Drop the `EventStream` into any class, or mix the `RespondsWithEventStream` trait into a
controller, and stream `text/event-stream` responses without hand-assembling the SSE wire format.

The package ships no service provider, no config file, and no artisan commands - it is a small set of primitives you
compose into your own endpoints.

## How It Works

An `EventStream` owns the transport lifecycle: it builds a `StreamedResponse` with the required SSE headers, runs a
polling loop, emits keep-alive heartbeats, watches for client disconnects, and handles callback errors. On each poll it
invokes your callback - optionally handing it an `Emitter` - then sleeps for the configured interval before polling
again.

The `Emitter` writes the SSE wire format for you: `emit()` produces `data:` blocks (JSON-encoding arrays and splitting
multi-line strings across separate `data:` fields), optionally preceded by an `event:` field, and `comment()` writes the
`:` keep-alive lines.

A few rules hold across the surface:

- **Unbounded by default.** With no ceiling configured the loop runs until the client disconnects - exactly the
  behaviour you want under php-fpm. Set `maxDuration` or `maxIterations` to make a stream self-terminate gracefully (see
  [Running SSE under Octane](#running-sse-under-octane)).
- **Extensible through protected hooks.** Subclass `EventStream` and override `onStreamStart()`, `onStreamEnd()`, or
  `shouldContinueAfterError()` to customise lifecycle behaviour. `onStreamEnd()` receives a `StreamTerminationReason`
  so you can tell a graceful ceiling-reached close from a client disconnect or an error.
- **No framework coupling beyond Laravel's helpers.** The trait accepts a plain integer HTTP status, so a consuming
  controller is not forced to depend on any particular enum or base-controller library.

## Installation

```bash
composer require sinemacula/laravel-sse
```

No service provider registration is required. The package ships no artisan commands or config files.

## Usage

### Standalone - from any class

```php
use SineMacula\Sse\EventStream;
use SineMacula\Sse\Emitter;

// maxDuration / maxIterations default to 0 (unbounded). Set a ceiling so the
// stream self-terminates and releases its worker - recommended under Octane.
$stream = new EventStream(heartbeatInterval: 20, maxDuration: 300);

return $stream->toResponse(function (Emitter $emitter): void {
    $emitter->emit(['type' => 'tick', 'at' => now()->toIso8601String()], 'update');
}, interval: 1);
```

### In a controller - via the trait

```php
use SineMacula\Sse\Concerns\RespondsWithEventStream;
use SineMacula\Sse\Emitter;

class EventController
{
    use RespondsWithEventStream;

    // Override to bound the stream. Defaults to 0 (unbounded).
    protected function maxStreamDuration(): int
    {
        return 300;
    }

    public function stream(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $this->respondWithEventStream(function (Emitter $emitter): void {
            $emitter->emit(['type' => 'tick'], 'update');
        });
    }
}
```

The trait exposes `heartbeatInterval()`, `maxStreamDuration()`, and `maxStreamIterations()` as overridable protected
methods. (They are methods, not constants: a `using` class cannot override a trait constant in PHP 8.2+.)

### Extending EventStream

Override the protected hooks to customise lifecycle behaviour. `onStreamEnd()` receives a `StreamTerminationReason`
so you can tell a graceful ceiling-reached close from a client disconnect or an error:

```php
use SineMacula\Sse\EventStream;
use SineMacula\Sse\Emitter;
use SineMacula\Sse\Enums\StreamTerminationReason;

class MyStream extends EventStream
{
    protected function onStreamStart(Emitter $emitter): void
    {
        $emitter->emit('connected', 'init');
    }

    protected function onStreamEnd(StreamTerminationReason $reason): void
    {
        if ($reason === StreamTerminationReason::MAX_DURATION) {
            // the configured ceiling was reached; the worker is being released
        }
    }

    protected function shouldContinueAfterError(\Throwable $exception, Emitter $emitter): bool
    {
        // return true to continue the loop, false to stop
        return false;
    }
}
```

## Running SSE under Octane

Under php-fpm each request owns its own process, so a long-lived stream is tolerable - the process dies at request end.
**Under Laravel Octane (Swoole / RoadRunner) the worker pool is fixed and shared, and the `EventStream` poll loop holds
one worker for the entire lifetime of a connection.** A single connection the client never closes pins that worker
indefinitely, and enough concurrent connections exhaust the pool and starve unrelated API traffic.

- **Set a ceiling under Octane.** Configure `maxStreamDuration()` (or the `maxDuration` constructor argument) so each
  stream self-terminates and returns its worker to the pool even if the client never disconnects. A starting point of
  **300 seconds** is reasonable for most Octane deployments; tune it to your worker count and traffic. The cap fires
  before Octane's own `max_execution_time` SIGKILL, so the close is graceful rather than a killed worker.
- **The default is unbounded.** With no ceiling set the loop runs until the client disconnects, exactly as under
  php-fpm - opt in to a ceiling; it is never imposed.
- **Offload high-concurrency streaming.** A self-terminating poll loop bounds a single stream, but it does not turn the
  worker model into a broadcast hub. For many concurrent connections at scale, offload to a dedicated connection layer
  (Laravel Reverb, Mercure, or a websocket server) that holds the persistent connections rather than relying on the
  poll loop.

## API

### `EventStream`

| Method                                                                                      | Description                                                                                                                               |
| ------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `__construct(int $heartbeatInterval = 20, int $maxDuration = 0, int $maxIterations = 0)`    | Construct a stream. `maxDuration` (seconds) and `maxIterations` are ceilings after which the stream self-terminates; `0` means unbounded. |
| `toResponse(callable $callback, int $interval = 1, int $status = 200, array $headers = [])` | Build and return a `StreamedResponse` with SSE headers and polling loop.                                                                  |
| `onStreamStart(Emitter $emitter)` _(protected)_                                             | Called once before the polling loop; emits the initial keep-alive by default.                                                             |
| `onStreamEnd(StreamTerminationReason $reason)` _(protected)_                                | Called after the loop exits, with the reason it ended; empty by default.                                                                  |
| `shouldContinueAfterError(\Throwable, Emitter)` _(protected)_                               | Called on callback exception; returns `false` to stop, `true` to continue.                                                                |

### `StreamTerminationReason`

A string-backed enum passed to `onStreamEnd()`: `CLIENT_DISCONNECT`, `ERROR`, `MAX_DURATION`, `MAX_ITERATIONS`.

### `Emitter`

| Method                                             | Description                                                                       |
| -------------------------------------------------- | --------------------------------------------------------------------------------- |
| `emit(array\|string $data, ?string $event = null)` | Write a `data:` block (optionally preceded by `event:`). Arrays are JSON-encoded. |
| `comment(string $text = '')`                       | Write a keep-alive comment line.                                                  |

### `RespondsWithEventStream` trait

| Method                                                                                                  | Description                                                                                       |
| ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `respondWithEventStream(callable $callback, int $interval = 1, int $status = 200, array $headers = [])` | Convenience wrapper around `EventStream::toResponse()`.                                           |
| `heartbeatInterval()` / `maxStreamDuration()` / `maxStreamIterations()` _(protected)_                   | Override to configure the heartbeat cadence and the stream ceilings. Default to `20` / `0` / `0`. |

## Requirements

- PHP ^8.3
- Laravel (illuminate/support) ^12.9

## Testing

```bash
composer test                # PHPUnit suite in parallel via Paratest
composer test:coverage       # suite with Clover coverage output
composer test:mutation       # Infection mutation gate (min MSI 100)
composer test:mutation:full  # full mutation suite without thresholds
composer check               # static analysis and lint via qlty
composer format              # format via qlty
composer smells              # duplication / complexity smells via qlty
composer bench               # PHPBench suite for the emitter hot paths
composer bench:ci            # PHPBench with CI artifact dump
composer bench:smoke         # single-rev pass to verify every subject runs
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of notable changes.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on branching, commits, code
quality, and pull requests.

## Security

If you discover a security vulnerability, please report it responsibly. See [SECURITY.md](SECURITY.md) for the
disclosure policy and contact details.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
