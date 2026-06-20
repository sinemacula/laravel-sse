<?php

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Tests\Fixtures\Support\FunctionOverrides;

/**
 * Base test case for the laravel-sse package.
 *
 * Registers no additional service providers — the SSE package ships no
 * provider of its own. FunctionOverrides are reset after each test so
 * namespace-scoped stubs do not bleed between test methods.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        FunctionOverrides::reset();

        parent::tearDown();
    }
}
