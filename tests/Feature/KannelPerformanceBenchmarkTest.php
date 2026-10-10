<?php

use App\DataObjects\Kannel\HttpFetchResult;
use App\Services\Kannel\JsonStatusParser;
use App\Services\Kannel\MetricsCollector;
use App\Services\Kannel\XmlStatusParser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function kannelFixture(string $name): string
{
    $path = base_path("tests/Fixtures/Kannel/{$name}");

    return file_get_contents($path) !== false ? file_get_contents($path) : '';
}

test('xml parser 3smsc fixture parses in <25ms avg over 25 iterations', function () {
    $payload = kannelFixture('status_3smscs.xml');
    $parser = app(XmlStatusParser::class);

    $times = [];
    $memPeak = 0;
    for ($i = 0; $i < 25; $i++) {
        $t0 = hrtime(true);
        $memBefore = memory_get_usage(true);
        $result = $parser->parse($payload);
        $memPeak = max($memPeak, memory_get_peak_usage(true) - $memBefore);
        $times[] = (hrtime(true) - $t0) / 1_000_000;
    }

    $avg = array_sum($times) / count($times);
    $max = max($times);

    expect($result[0] ?? null)->not->toBeNull();
    expect($avg)->toBeLessThan(25.0);
    expect($max)->toBeLessThan(75.0);
    expect($memPeak)->toBeLessThan(8 * 1024 * 1024);
});

test('json parser 2smsc fixture parses in <25ms avg over 25 iterations', function () {
    $payload = kannelFixture('status_2smscs.json');
    $parser = app(JsonStatusParser::class);

    $times = [];
    for ($i = 0; $i < 25; $i++) {
        $t0 = hrtime(true);
        $parser->parse($payload);
        $times[] = (hrtime(true) - $t0) / 1_000_000;
    }

    $avg = array_sum($times) / count($times);
    expect($avg)->toBeLessThan(25.0);
});

test('MetricsCollector parser dispatch + iteration runs under 50ms over 10 iterations with xml fixture', function () {
    $payload = kannelFixture('status_3smscs.xml');
    $collector = app(MetricsCollector::class);

    $times = [];
    for ($i = 0; $i < 10; $i++) {
        $t0 = hrtime(true);
        $mock = new HttpFetchResult(
            success: true,
            status: 200,
            body: $payload,
            durationMs: 0,
            retries: 0,
            errorMessage: null,
        );
        $parser = Closure::bind(function ($result, $fmt) use ($collector) {
            return $collector->resolveParser($result, $fmt);
        }, null, MetricsCollector::class);
        $parser($mock, 'xml')->parse($payload);
        $times[] = (hrtime(true) - $t0) / 1_000_000;
    }

    $avg = array_sum($times) / count($times);
    expect($avg)->toBeLessThan(50.0);
});
