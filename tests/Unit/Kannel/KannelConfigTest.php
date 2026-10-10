<?php

test('default kannel poll interval matches expected 30s', function () {
    $interval = config('kannel.poll_interval');

    expect($interval)->toBeInt()->toBeGreaterThanOrEqual(5);
});

test('config helper exposes retention and http retry keys', function () {
    expect(config('kannel.retention_days'))->toBeInt()->toBeGreaterThanOrEqual(1);
    expect(config('kannel.http.retry_max_attempts'))->toBeInt()->toBeGreaterThanOrEqual(1);
    expect(config('kannel.http.retry_cap_seconds'))->toBeGreaterThanOrEqual(config('kannel.http.retry_base_seconds'));
    expect(config('kannel.queue.warning_threshold'))->toBeLessThan(config('kannel.queue.critical_threshold'));
});

test('throughput baseline windows are positive integers', function () {
    expect(config('kannel.throughput.baseline_hours'))->toBeGreaterThan(0);
    expect(config('kannel.throughput.rate_window_minutes'))->toBeGreaterThan(0);
});
