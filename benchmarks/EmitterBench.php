<?php

declare(strict_types = 1);

namespace Benchmarks;

use PhpBench\Attributes as Bench;
use SineMacula\Sse\Emitter;

/**
 * Benchmarks for the SSE emitter hot paths.
 *
 * The emitter is the only CPU-bound surface in the package - it formats
 * every event written to the wire, so its per-call cost compounds across a
 * stream. Output is captured and discarded so the wire writes do not leak
 * into the benchmark runner.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\OutputTimeUnit('microseconds')]
final class EmitterBench
{
    /** @var \SineMacula\Sse\Emitter The emitter under benchmark. */
    private Emitter $emitter;

    /**
     * Construct the benchmark with a reusable emitter. The emitter holds no
     * state, so a single instance is shared across every revolution.
     */
    public function __construct()
    {
        $this->emitter = new Emitter;
    }

    /**
     * Benchmark emitting a single-line string payload.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchEmitString(): void
    {
        ob_start();
        $this->emitter->emit('hello world');
        ob_get_clean();
    }

    /**
     * Benchmark emitting a multi-line payload through the line splitter.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchEmitMultilineString(): void
    {
        ob_start();
        $this->emitter->emit("line one\nline two\nline three");
        ob_get_clean();
    }

    /**
     * Benchmark emitting an array payload through JSON encoding.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchEmitArray(): void
    {
        ob_start();
        $this->emitter->emit(['type' => 'tick', 'sequence' => 42, 'at' => '2026-01-01T00:00:00+00:00']);
        ob_get_clean();
    }

    /**
     * Benchmark emitting an array payload preceded by a named event field.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchEmitArrayWithEvent(): void
    {
        ob_start();
        $this->emitter->emit(['type' => 'tick'], 'update');
        ob_get_clean();
    }

    /**
     * Benchmark writing a keep-alive comment line.
     *
     * @return void
     */
    #[Bench\Iterations(5)]
    #[Bench\Revs(1000)]
    #[Bench\Warmup(2)]
    public function benchComment(): void
    {
        ob_start();
        $this->emitter->comment();
        ob_get_clean();
    }
}
