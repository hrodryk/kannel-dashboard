<?php

use App\Models\KannelMetricSnapshot;
use App\Models\KannelSmscMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('expected kannel tables exist with required columns', function () {
    $tables = [
        'kannel_endpoints' => ['id', 'name', 'url', 'admin_password', 'poll_interval_seconds', 'status', 'enabled', 'last_polled_at'],
        'kannel_metric_snapshots' => ['id', 'endpoint_id', 'polled_at', 'success', 'mo_total', 'mt_total', 'mo_rate', 'mt_rate', 'inbound_queue', 'outbound_queue', 'errors'],
        'kannel_smsc_metrics' => ['id', 'snapshot_id', 'endpoint_id', 'smsc_name', 'smsc_state', 'latency_ms', 'mo_count', 'mt_count', 'queued'],
        'kannel_hourly_rollups' => ['id', 'endpoint_id', 'bucket_hour', 'mo_volume', 'mt_volume', 'avg_mo_rate', 'peak_mo_rate', 'samples'],
        'kannel_alert_rules' => ['id', 'endpoint_id', 'name', 'type', 'severity', 'threshold', 'channels', 'cooldown_minutes'],
        'kannel_alert_events' => ['id', 'rule_id', 'fingerprint', 'state', 'severity', 'title', 'message', 'fired_at', 'last_notified_at'],
    ];

    foreach ($tables as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeTrue("$table missing column $column");
        }
    }
});

test('kannel_metric_snapshots has endpoint_id + polled_at composite index', function () {
    $indexes = collect(DB::select('PRAGMA index_list(kannel_metric_snapshots)'))
        ->pluck('name');

    $matchFound = $indexes->contains(fn (string $name): bool => str_contains($name, 'endpoint_id') && ! str_contains($name, 'unique'));

    expect($matchFound)->toBeTrue('Snapshot endpoint/polled_at index missing');
});

test('cascade delete removes related SMSC metrics when snapshot deleted', function () {
    $snapshot = KannelMetricSnapshot::factory()->create();
    $smsc = KannelSmscMetric::factory()->create([
        'snapshot_id' => $snapshot->id,
        'endpoint_id' => $snapshot->endpoint_id,
    ]);

    expect(KannelSmscMetric::find($smsc->id))->not->toBeNull();
    $snapshot->delete();
    expect(KannelSmscMetric::find($smsc->id))->toBeNull();
});
