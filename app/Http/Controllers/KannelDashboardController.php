<?php

namespace App\Http\Controllers;

use App\DataObjects\Kannel\KannelSnapshotData;
use App\DataObjects\Kannel\SmscMetricsData;
use App\Enums\AlertRuleType;
use App\Enums\EndpointStatus;
use App\Enums\SMSCState;
use App\Models\KannelAlertEvent;
use App\Models\KannelEndpoint;
use App\Services\Kannel\MetricsCollector;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class KannelDashboardController extends Controller
{
    /**
     * @var int
     */
    private const SAMPLE_WINDOW_SECONDS = 3600;

    /**
     * @var int
     */
    private const MAX_SAMPLES_PER_ENDPOINT = 360;

    public function __construct(
        protected MetricsCollector $metricsCollector,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = (bool) $user->can('manage_kannel');

        $endpoints = KannelEndpoint::query()
            ->orderBy('name')
            ->get();

        $live = $this->collectLiveData($endpoints->where('enabled', true)->all());

        $rangeHours = max(1, min(24 * 7, $request->integer('range_hours', 1)));

        $kpis = Inertia::defer(function () use ($live, $endpoints) {
            return $this->computeKpis($live, $endpoints->all());
        });

        $smscGrid = Inertia::defer(function () use ($live) {
            return $this->buildSmscGrid($live);
        });

        $throughputBuckets = Inertia::defer(function () use ($live, $rangeHours) {
            return $this->buildThroughputBuckets($rangeHours, $live);
        });

        $queues = Inertia::defer(function () use ($live) {
            return $this->buildQueues($live);
        });

        $recentAlerts = Inertia::defer(function () use ($endpoints) {
            return $this->buildRecentAlerts($endpoints->pluck('id')->all());
        });

        return Inertia::render('Dashboard', [
            'can_manage_kannel' => $canManage,
            'endpoints' => $endpoints->map(fn (KannelEndpoint $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'status' => $e->status instanceof EndpointStatus ? $e->status->value : (is_string($e->status) ? $e->status : EndpointStatus::Disabled->value),
                'enabled' => (bool) $e->enabled,
                'admin_url' => $e->admin_url,
                'last_polled_at' => $live[$e->id]['fetched_at'] ?? $e->last_polled_at,
                'consecutive_failures' => (int) ($live[$e->id]['consecutive_failures'] ?? $e->consecutive_failures ?? 0),
                'last_error' => $live[$e->id]['last_error'] ?? $e->last_error,
                'last_success_at' => $e->last_success_at,
            ])->all(),
            'kpis' => $kpis,
            'smsc_grid' => $smscGrid,
            'throughput' => $throughputBuckets,
            'queues' => $queues,
            'recent_alerts' => $recentAlerts,
            'poll_interval_seconds' => (int) config('kannel.poll_interval_seconds', 30),
        ]);
    }

    /**
     * @param  array<int, KannelEndpoint>  $endpoints
     * @return array<int, array{
     *     endpoint: KannelEndpoint,
     *     success: bool,
     *     snapshot: KannelSnapshotData,
     *     smscs: list<SmscMetricsData>,
     *     duration_ms: int,
     *     fetched_at: Carbon,
     *     last_error: string|null,
     *     consecutive_failures: int,
     *     rates: array{mo_rate:float, mt_rate:float},
     * }>
     */
    private function collectLiveData(array $endpoints): array
    {
        $live = [];
        $now = Carbon::now();

        foreach ($endpoints as $endpoint) {
            $start = hrtime(true);
            try {
                $collected = $this->metricsCollector->collect($endpoint, true);
                $success = $collected['fetch']->success;
                $durationMs = (int) round(($collected['fetch']->durationMs));
                $snapshot = $collected['snapshot'];
                $smscs = $collected['smscs'];
                $err = $success ? null : ($collected['fetch']->errorMessage ?? 'Unknown error');
            } catch (\Throwable $e) {
                $success = false;
                $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
                $snapshot = new KannelSnapshotData(collectedAt: $now->toDateTimeImmutable());
                $smscs = [];
                $err = $e->getMessage();
            }

            $prevSample = $this->appendEndpointSample($endpoint, $snapshot, $success, $now);
            $rates = $this->computeRatesFromSamples($endpoint->id, $prevSample, $snapshot, $now);

            if ($success) {
                $endpoint->markHealthy($now);
            } else {
                $endpoint->markUnhealthy((string) $err, $now);
            }

            $live[$endpoint->id] = [
                'endpoint' => $endpoint,
                'success' => $success,
                'snapshot' => $snapshot,
                'smscs' => $smscs,
                'duration_ms' => $durationMs,
                'fetched_at' => $now,
                'last_error' => $err,
                'consecutive_failures' => (int) $endpoint->consecutive_failures,
                'rates' => $rates,
            ];
        }

        return $live;
    }

    /**
     * @return array{t:int, mo:int, mt:int, ok:bool}|null
     */
    private function appendEndpointSample(KannelEndpoint $endpoint, KannelSnapshotData $snapshot, bool $success, Carbon $now): ?array
    {
        $key = "kannel:samples:{$endpoint->id}";
        /** @var list<array{t:int,mo:int,mt:int,ok:bool}> $samples */
        $samples = Cache::get($key, []);
        if (! is_array($samples)) {
            $samples = [];
        }

        $cutoff = (int) $now->timestamp - self::SAMPLE_WINDOW_SECONDS;
        $samples = array_values(array_filter($samples, static fn (array $s): bool => (int) $s['t'] >= $cutoff));

        $newSample = [
            't' => (int) $now->timestamp,
            'mo' => $snapshot->moTotal,
            'mt' => $snapshot->mtTotal,
            'ok' => $success,
        ];

        $prev = end($samples);
        if ($prev === false || $prev === []) {
            $prev = null;
        }

        $samples[] = $newSample;
        if (count($samples) > self::MAX_SAMPLES_PER_ENDPOINT) {
            $samples = array_slice($samples, -self::MAX_SAMPLES_PER_ENDPOINT);
        }

        Cache::put($key, $samples, now()->addHours(2));

        return is_array($prev) ? $prev : null;
    }

    /**
     * @return array{mo_rate:float, mt_rate:float}
     */
    private function computeRatesFromSamples(int $endpointId, mixed $prevSample, KannelSnapshotData $snapshot, Carbon $now): array
    {
        if (! is_array($prevSample) || ! isset($prevSample['t'], $prevSample['mo'], $prevSample['mt'], $prevSample['ok']) || ! $prevSample['ok']) {
            return ['mo_rate' => 0.0, 'mt_rate' => 0.0];
        }

        $dt = max(1, (int) $now->timestamp - (int) $prevSample['t']);
        if ($dt > 600) {
            return ['mo_rate' => 0.0, 'mt_rate' => 0.0];
        }

        $moDelta = max(0, $snapshot->moTotal - (int) $prevSample['mo']);
        $mtDelta = max(0, $snapshot->mtTotal - (int) $prevSample['mt']);

        return [
            'mo_rate' => round($moDelta / $dt, 3),
            'mt_rate' => round($mtDelta / $dt, 3),
        ];
    }

    /**
     * @param  array<int, mixed>  $live
     * @param  array<int, KannelEndpoint>  $endpoints
     * @return array<string, mixed>
     */
    private function computeKpis(array $live, array $endpoints): array
    {
        $totalEndpoints = count($endpoints);
        $activeEndpoints = 0;
        $unhealthyEndpointCount = 0;
        $totalSmscs = 0;
        $smscDownCount = 0;
        $totalMo = 0;
        $totalMt = 0;
        $sumMoRate = 0.0;
        $sumMtRate = 0.0;
        $sumFailed = 0;
        $sumQueues = 0;
        $lastPolled = null;

        foreach ($live as $row) {
            /** @var KannelSnapshotData $snap */
            $snap = $row['snapshot'];
            /** @var KannelEndpoint $ep */
            $ep = $row['endpoint'];

            if (($ep->status ?? null) === EndpointStatus::Healthy) {
                $activeEndpoints++;
            }
            if (in_array($ep->status, [EndpointStatus::Degraded, EndpointStatus::Unreachable], true) && (bool) $ep->enabled) {
                $unhealthyEndpointCount++;
            }

            $totalMo += $snap->moTotal;
            $totalMt += $snap->mtTotal;
            $sumFailed += $snap->failedCount;
            $smsQSum = 0;
            foreach (($row['smscs'] ?? []) as $smsc) {
                /** @var SmscMetricsData $smsc */
                $smsQSum += $smsc->queued;
            }
            $sumQueues += $snap->inboundQueue + $snap->outboundQueue + $snap->dlrQueue + $snap->smsQueued + $smsQSum;
            $sumMoRate += (float) ($row['rates']['mo_rate'] ?? 0.0);
            $sumMtRate += (float) ($row['rates']['mt_rate'] ?? 0.0);

            foreach (($row['smscs'] ?? []) as $smsc) {
                /** @var SmscMetricsData $smsc */
                $totalSmscs++;
                if (in_array($smsc->state, [SMSCState::Disconnected, SMSCState::Unknown], true)) {
                    $smscDownCount++;
                }
            }

            if ($row['fetched_at'] instanceof Carbon) {
                if ($lastPolled === null || $row['fetched_at']->gt($lastPolled)) {
                    $lastPolled = $row['fetched_at'];
                }
            }
        }

        $liveCount = $live === [] ? 1 : count($live);

        return [
            'total_endpoints' => $totalEndpoints,
            'active_endpoints' => $activeEndpoints,
            'unhealthy_endpoints' => $unhealthyEndpointCount,
            'total_smscs' => $totalSmscs,
            'smsc_down_count' => $smscDownCount,
            'mo_total' => $totalMo,
            'mt_total' => $totalMt,
            'mo_rate' => round($sumMoRate / $liveCount, 2),
            'mt_rate' => round($sumMtRate / $liveCount, 2),
            'failed_total' => $sumFailed,
            'queue_total' => $sumQueues,
            'last_polled_at' => $lastPolled,
        ];
    }

    /**
     * @param  array<int, mixed>  $live
     * @return list<array<string, mixed>>
     */
    private function buildSmscGrid(array $live): array
    {
        $out = [];
        foreach ($live as $row) {
            $ep = $row['endpoint'];
            $prevBySmsc = $this->getSmscPrevSample($ep->id);
            $fetchedAt = $row['fetched_at'];

            foreach (($row['smscs'] ?? []) as $smsc) {
                /** @var SmscMetricsData $smsc */
                $prev = $prevBySmsc[$smsc->smscName] ?? null;
                $dt = is_array($prev) && isset($prev['t']) ? max(1, $fetchedAt->timestamp - (int) $prev['t']) : 0;
                if ($dt > 0 && $dt <= 600) {
                    $moRate = round(max(0, $smsc->moCount - (int) ($prev['mo'] ?? 0)) / $dt, 3);
                    $mtRate = round(max(0, $smsc->mtCount - (int) ($prev['mt'] ?? 0)) / $dt, 3);
                } else {
                    $moRate = 0.0;
                    $mtRate = 0.0;
                }
                $prevBySmsc[$smsc->smscName] = [
                    't' => $fetchedAt->timestamp,
                    'mo' => $smsc->moCount,
                    'mt' => $smsc->mtCount,
                ];

                $out[] = [
                    'id' => $ep->id.'::'.$smsc->smscName,
                    'endpoint_id' => $ep->id,
                    'endpoint_name' => $ep->name ?? 'Endpoint '.$ep->id,
                    'smsc_name' => $smsc->smscName,
                    'smsc_id' => $smsc->smscId,
                    'state' => $smsc->state->value,
                    'state_label' => $smsc->state->label(),
                    'operational' => $smsc->state->isOperational(),
                    'mo_total' => $smsc->moCount,
                    'mt_total' => $smsc->mtCount,
                    'mo_rate' => $moRate,
                    'mt_rate' => $mtRate,
                    'failed' => $smsc->failed,
                    'queued' => $smsc->queued,
                    'latency_ms' => $smsc->latencyMs,
                    'last_seen_at' => $smsc->lastSeenAt,
                    'collected_at' => $fetchedAt,
                ];
            }

            $this->storeSmscPrevSamples($ep->id, $prevBySmsc, $fetchedAt);
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp((string) $a['smsc_name'], (string) $b['smsc_name']));

        return $out;
    }

    /**
     * @return array<string, array{t:int,mo:int,mt:int}>
     */
    private function getSmscPrevSample(int $endpointId): array
    {
        /** @var mixed $cached */
        $cached = Cache::get("kannel:smsc_samples:{$endpointId}", []);

        return is_array($cached) ? $cached : [];
    }

    /**
     * @param  array<string, array{t:int,mo:int,mt:int}>  $samples
     */
    private function storeSmscPrevSamples(int $endpointId, array $samples, Carbon $now): void
    {
        Cache::put("kannel:smsc_samples:{$endpointId}", $samples, $now->addMinutes(30));
    }

    /**
     * @param  array<int, mixed>  $live
     * @return list<array{bucket:string,mo_rate:float,mt_rate:float,mo_delta:int,mt_delta:int,peak_mt_rate:float,samples:int}>
     */
    private function buildThroughputBuckets(int $rangeHours, array $live): array
    {
        $rangeHours = max(1, min(24 * 7, $rangeHours));
        $windowSeconds = $rangeHours * 3600;
        $now = Carbon::now();
        $cutoff = (int) $now->timestamp - $windowSeconds;

        $buckets = [];
        if ($rangeHours <= 1) {
            $bucketSizeSeconds = 30;
        } elseif ($rangeHours <= 24) {
            $bucketSizeSeconds = 300;
        } else {
            $bucketSizeSeconds = 1800;
        }

        foreach (array_keys($live) as $endpointId) {
            /** @var list<array{t:int,mo:int,mt:int,ok?:bool}> $samples */
            $samples = Cache::get("kannel:samples:{$endpointId}", []);
            if (! is_array($samples)) {
                continue;
            }
            $samples = array_values(array_filter(
                $samples,
                static fn (mixed $s): bool => is_array($s) && isset($s['t']) && (int) $s['t'] >= $cutoff,
            ));

            for ($i = 1; $i < count($samples); $i++) {
                $prev = $samples[$i - 1];
                $cur = $samples[$i];
                $dt = max(1, (int) $cur['t'] - (int) $prev['t']);
                if ($dt > 600) {
                    continue;
                }
                $bucketTs = (int) (floor((int) $cur['t'] / $bucketSizeSeconds) * $bucketSizeSeconds);
                $moDelta = max(0, (int) $cur['mo'] - (int) $prev['mo']);
                $mtDelta = max(0, (int) $cur['mt'] - (int) $prev['mt']);
                $moRate = $dt > 0 ? round($moDelta / $dt, 3) : 0.0;
                $mtRate = $dt > 0 ? round($mtDelta / $dt, 3) : 0.0;

                if (! isset($buckets[$bucketTs])) {
                    $buckets[$bucketTs] = [
                        'bucket' => $bucketTs,
                        'mo_rate_sum' => 0.0,
                        'mt_rate_sum' => 0.0,
                        'mo_delta_sum' => 0,
                        'mt_delta_sum' => 0,
                        'peak_mt_rate' => 0.0,
                        'samples' => 0,
                    ];
                }
                $buckets[$bucketTs]['mo_rate_sum'] += $moRate;
                $buckets[$bucketTs]['mt_rate_sum'] += $mtRate;
                $buckets[$bucketTs]['mo_delta_sum'] += $moDelta;
                $buckets[$bucketTs]['mt_delta_sum'] += $mtDelta;
                $buckets[$bucketTs]['peak_mt_rate'] = max($buckets[$bucketTs]['peak_mt_rate'], $mtRate);
                $buckets[$bucketTs]['samples']++;
            }
        }

        ksort($buckets);

        $out = [];
        foreach ($buckets as $b) {
            $samples = max(1, (int) $b['samples']);
            $out[] = [
                'bucket' => Carbon::createFromTimestamp($b['bucket'])->toIso8601String(),
                'mo_rate' => round((float) $b['mo_rate_sum'] / $samples, 3),
                'mt_rate' => round((float) $b['mt_rate_sum'] / $samples, 3),
                'mo_delta' => (int) $b['mo_delta_sum'],
                'mt_delta' => (int) $b['mt_delta_sum'],
                'peak_mt_rate' => round((float) $b['peak_mt_rate'], 3),
                'samples' => (int) $b['samples'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $live
     * @return list<array<string, mixed>>
     */
    private function buildQueues(array $live): array
    {
        $out = [];
        foreach ($live as $row) {
            /** @var KannelSnapshotData $snap */
            $snap = $row['snapshot'];
            /** @var KannelEndpoint $ep */
            $ep = $row['endpoint'];
            $perSmscQueued = 0;
            foreach (($row['smscs'] ?? []) as $smsc) {
                /** @var SmscMetricsData $smsc */
                $perSmscQueued += $smsc->queued;
            }

            $inboundQ = $snap->inboundQueue;
            $outboundQ = $snap->outboundQueue + $perSmscQueued + $snap->smsQueued;
            $dlrQ = $snap->dlrQueue;
            $buffered = $snap->bufferedMessages;
            $stored = $snap->storeSize > 0 ? $snap->storeSize : max($snap->storeUsed, 0);

            $out[] = [
                'endpoint_id' => $ep->id,
                'endpoint_name' => $ep->name ?? 'Endpoint '.$ep->id,
                'inbound_queue' => $inboundQ,
                'outbound_queue' => $outboundQ,
                'dlr_queue' => $dlrQ,
                'buffered' => $buffered,
                'stored' => $stored,
                'sms_queued' => $snap->smsQueued,
                'smsc_queued_total' => $perSmscQueued,
                'peak_outbound_queue' => max($outboundQ, $perSmscQueued),
                'updated_at' => $row['fetched_at'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $endpointIds
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentAlerts(array $endpointIds, int $limit = 50): array
    {
        return KannelAlertEvent::query()
            ->when($endpointIds !== [], fn ($q) => $q->whereIn('endpoint_id', $endpointIds))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->with('rule:id,name,type,severity,enabled')
            ->get()
            ->map(function (KannelAlertEvent $event): array {
                $rule = $event->rule;
                $ruleType = $rule?->type;
                $ruleTypeValue = $ruleType instanceof AlertRuleType
                    ? $ruleType->value
                    : (is_string($ruleType) ? $ruleType : '');

                return [
                    'id' => $event->id,
                    'fingerprint' => $event->fingerprint,
                    'state' => $event->state,
                    'severity' => $event->severity instanceof \BackedEnum ? $event->severity->value : (is_string($event->severity) ? $event->severity : null),
                    'title' => $event->title,
                    'message' => $event->message,
                    'endpoint_id' => $event->endpoint_id,
                    'smsc_name' => $event->smsc_name,
                    'fired_at' => $event->fired_at,
                    'resolved_at' => $event->resolved_at,
                    'last_notified_at' => $event->last_notified_at,
                    'notify_attempts' => (int) $event->notify_attempts,
                    'rule_name' => $rule?->name,
                    'rule_type' => $ruleTypeValue,
                ];
            })
            ->all();
    }
}
