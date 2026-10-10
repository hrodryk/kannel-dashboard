<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;

test('kannel:prune command validates positive day count', function () {
    $kernel = app(Kernel::class);

    $exitZero = $kernel->call('kannel:prune', ['--days' => 0]);
    expect($exitZero)->toBe(Command::INVALID);
    expect($kernel->output())->toContain('Retention days must be positive');

    $exitNegative = $kernel->call('kannel:prune', ['--days' => -5]);
    expect($exitNegative)->toBe(Command::INVALID);
    expect($kernel->output())->toContain('Retention days must be positive');
});
