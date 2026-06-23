<?php

use SineMacula\CodingStandards\PhpCsFixerConfig;

return PhpCsFixerConfig::make([
    dirname(__DIR__, 2) . '/src',
    dirname(__DIR__, 2) . '/benchmarks',
    dirname(__DIR__, 2) . '/tests',
]);
