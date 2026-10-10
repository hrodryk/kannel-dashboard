<?php

use App\Models\KannelEndpoint;
use App\Models\KannelHourlyRollup;
use App\Models\KannelMetricSnapshot;
use App\Models\KannelSmscMetric;
use Illuminate\Support\Facades\Http;

test('kannel:poll stores snapshot and SMSC metrics when fixture succeeds', function () {
    $xml = file_get_contents(base_path('tests/Fixtures/Kannel/status_3smscs.xml'));
    Http::fake([
        '*/status.xml' => Http::response($xml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $endpoint = KannelEndpoint::factory()->create([
        'url' => 'http://kannel.test:13000',
    ]);

    $this->artisan('kannel:poll', ['--endpoint' => $endpoint->id])
        ->assertSuccessful();

    $snapshot = KannelMetricSnapshot::query()
        ->where('endpoint_id', $endpoint->id)
        ->latest('polled_at')
        ->first();

    expect($snapshot)->not->toBeNull();
    expect($snapshot->success)->toBeTrue();
    expect($snapshot->mo_total)->toBe(120560);
    expect($snapshot->mt_total)->toBe(298340);
    expect($snapshot->outbound_queue)->toBe(534);
    expect($snapshot->errors)->toHaveCount(3);

    $smscCount = KannelSmscMetric::query()->where('snapshot_id', $snapshot->id)->count();
    expect($smscCount)->toBe(3);

    $a = KannelSmscMetric::query()->where('snapshot_id', $snapshot->id)
        ->where('smsc_name', 'SMSC_A')->first();

    expect($a->smsc_state->value)->toBe('connected');
    expect($a->mo_count)->toBe(50320);
    expect($a->latency_ms)->toBe(18);
});

test('kannel:poll continues on single endpoint failure', function () {
    $xml = file_get_contents(base_path('tests/Fixtures/Kannel/status_3smscs.xml'));
    Http::fake([
        'ok.test/*' => Http::response($xml, 200),
        'fail.test/*' => Http::response('', 500),
    ]);

    KannelEndpoint::factory()->count(2)->sequence(
        ['name' => 'OK endpoint', 'url' => 'http://ok.test:13000'],
        ['name' => 'FAIL endpoint', 'url' => 'http://fail.test:13000', 'timeout_seconds' => 1],
    )->create();

    $this->artisan('kannel:poll')
        ->assertFailed();

    $success = KannelMetricSnapshot::query()->whereHas('endpoint', fn ($q) => $q->where('name', 'OK endpoint'))->count();
    $failed = KannelMetricSnapshot::query()->whereHas('endpoint', fn ($q) => $q->where('name', 'FAIL endpoint'))->count();
    expect($success)->toBe(1);
    expect($failed)->toBe(1);
});

test('kannel:poll computes correct mo/mt rates between snapshots', function () {
    $xml1 = str_replace(
        ['<received>120560</received>', '<sent>298340</sent>'],
        ['<received>1000</received>', '<sent>2000</sent>'],
        (string) file_get_contents(base_path('tests/Fixtures/Kannel/status_3smscs.xml')),
    );
    $xml2 = str_replace(
        ['<received>120560</received>', '<sent>298340</sent>'],
        ['<received>1700</received>', '<sent>3200</sent>'],
        (string) file_get_contents(base_path('tests/Fixtures/Kannel/status_3smscs.xml')),
    );

    $endpoint = KannelEndpoint::factory()->create(['url' => 'http://kannel.test:13000']);

    Http::fakeSequence('*/status.xml')
        ->push($xml1, 200)
        ->push($xml2, 200);

    $this->travel(-60)->seconds();
    $this->artisan('kannel:poll', ['--endpoint' => $endpoint->id]);
    $this->travelBack();
    $this->artisan('kannel:poll', ['--endpoint' => $endpoint->id]);

    $latest = KannelMetricSnapshot::query()->where('endpoint_id', $endpoint->id)
        ->orderByDesc('id')->limit(2)->get();

    $newer = $latest->first();
    expect($newer->mo_rate)->toBeGreaterThan(0);
    expect($newer->mt_rate)->toBeGreaterThan(0);
});

test('kannel:rollup upserts expected hourly buckets', function () {
    $endpoint = KannelEndpoint::factory()->create();
    $baseHour = now()->startOfHour();

    KannelMetricSnapshot::factory()->count(25)->sequence(
        fn ($sequence) => [
            'endpoint_id' => $endpoint->id,
            'polled_at' => (clone $baseHour)->addMinutes($sequence->index),
            'mo_total' => 1000 + $sequence->index * 50,
            'mt_total' => 5000 + $sequence->index * 120,
            'mo_rate' => 50 / 60,
            'mt_rate' => 120 / 60,
            'inbound_queue' => 10 + $sequence->index,
            'outbound_queue' => 20 + $sequence->index * 2,
        ],
    )->create();

    $this->artisan('kannel:rollup', ['--endpoint' => $endpoint->id])->assertSuccessful();

    $rollups = KannelHourlyRollup::query()->where('endpoint_id', $endpoint->id)->count();
    expect($rollups)->toBe(1);
});

test('kannel:prune removes data older than retention days', function () {
    $endpoint = KannelEndpoint::factory()->create();

    KannelMetricSnapshot::factory()->count(2)->sequence(
        ['endpoint_id' => $endpoint->id, 'polled_at' => now()->subDays(35)],
        ['endpoint_id' => $endpoint->id, 'polled_at' => now()->subDay()],
    )->create()->each(function (KannelMetricSnapshot $snapshot) use ($endpoint) {
        KannelSmscMetric::factory()->create([
            'snapshot_id' => $snapshot->id,
            'endpoint_id' => $endpoint->id,
        ]);
    });

    KannelHourlyRollup::factory()->count(2)->sequence(
        ['endpoint_id' => $endpoint->id, 'bucket_hour' => now()->subDays(35)->startOfHour()],
        ['endpoint_id' => $endpoint->id, 'bucket_hour' => now()->subDay()->startOfHour()],
    )->create();

    $this->artisan('kannel:prune', ['--days' => 7])->assertSuccessful();

    expect(KannelMetricSnapshot::count())->toBe(1);
    expect(KannelSmscMetric::count())->toBe(1);
    expect(KannelHourlyRollup::count())->toBe(1);
});
