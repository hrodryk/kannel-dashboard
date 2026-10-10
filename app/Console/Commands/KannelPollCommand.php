<?php

namespace App\Console\Commands;

use App\Enums\EndpointStatus;
use App\Models\KannelAlertRule;
use App\Models\KannelEndpoint;
use App\Models\KannelMetricSnapshot;
use App\Models\KannelSmscMetric;
use App\Services\Kannel\Alerting\AlertManager;
use App\Services\Kannel\MetricsCollector;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('kannel:poll {--endpoint= : Poll only a single endpoint by ID}')]
#[Description('Poll Kannel admin endpoints, persist metric snapshots, compute rates, and evaluate alerts.')]
class KannelPollCommand extends Command
{
    public function handle(MetricsCollector $collector, KannelRollupCommand $rollup, AlertManager $alertManager): int
    {
        $query = KannelEndpoint::query()
            ->where('enabled', true);

        if ($this->option('endpoint')) {
            $query->where('id', (int) $this->option('endpoint'));
        }

        $endpoints = $query->get();

        if ($endpoints->isEmpty()) {
            $this->warn('No enabled Kannel endpoints configured.');

            return self::SUCCESS;
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($endpoints as $endpoint) {
            try {
                $result = $this->pollEndpoint($endpoint, $collector);
                if ($result) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            } catch (Throwable $e) {
                $failCount++;
                $this->handleEndpointError($endpoint, 'Unexpected poll error: '.$e->getMessage());
                report($e);
            }
        }

        try {
            $this->callSilent($rollup->getName(), []);
        } catch (Throwable $e) {
            Log::channel('kannel')->warning('kannel.poll.rollup_failed', ['message' => $e->getMessage()]);
        }

        $this->evaluateAlerts($endpoints, $alertManager);

        $this->info(sprintf('Polled %d endpoints: %d success, %d failed.', $endpoints->count(), $successCount, $failCount));

        return $failCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Collection<int, KannelEndpoint>  $endpoints
     */
    private function evaluateAlerts(Collection $endpoints, AlertManager $alertManager): void
    {
        $ids = $endpoints->pluck('id');

        $latestSnapshots = KannelMetricSnapshot::query()
            ->whereIn('endpoint_id', $ids)
            ->whereIn('id', function ($q) {
                $q->selectRaw('MAX(id)')
                    ->from('kannel_metric_snapshots')
                    ->groupBy('endpoint_id');
            })
            ->get()
            ->keyBy('endpoint_id');

        $snapshotIds = $latestSnapshots->pluck('id');

        $latestSmscs = KannelSmscMetric::query()
            ->whereIn('snapshot_id', $snapshotIds)
            ->get();

        $rules = KannelAlertRule::query()
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('endpoint_id')->orWhereIn('endpoint_id', $ids))
            ->get();

        $alertManager->runEvaluation($rules, [
            'endpoints' => $endpoints->all(),
            'latest_snapshots' => $latestSnapshots->values()->all(),
            'latest_smscs' => $latestSmscs->all(),
        ]);
    }

    private function pollEndpoint(KannelEndpoint $endpoint, MetricsCollector $collector): bool
    {
        $collected = $collector->collect($endpoint);
        $fetch = $collected['fetch'];
        $snapshotData = $collected['snapshot'];
        $smscs = $collected['smscs'];

        $previousSnapshot = KannelMetricSnapshot::query()
            ->where('endpoint_id', $endpoint->id)
            ->where('success', true)
            ->orderByDesc('polled_at')
            ->first();

        $rateSeconds = max(1, config('kannel.throughput.rate_window_minutes', 5) * 60);
        $moRate = 0.0;
        $mtRate = 0.0;

        if ($fetch->success && $previousSnapshot !== null) {
            $deltaSeconds = max(1, Carbon::parse($snapshotData->collectedAt())
                ->diffInSeconds(Carbon::instance($previousSnapshot->polled_at)));
            if ($deltaSeconds <= $rateSeconds) {
                $moDelta = max(0, $snapshotData->moTotal - $previousSnapshot->mo_total);
                $mtDelta = max(0, $snapshotData->mtTotal - $previousSnapshot->mt_total);
                $moRate = round($moDelta / $deltaSeconds, 4);
                $mtRate = round($mtDelta / $deltaSeconds, 4);
            }
        }

        $polledAt = Carbon::parse($snapshotData->collectedAt());

        $snapshot = DB::transaction(function () use (
            $endpoint,
            $fetch,
            $snapshotData,
            $smscs,
            $moRate,
            $mtRate,
            $polledAt,
        ) {
            /** @var KannelMetricSnapshot $snapshot */
            $snapshot = $endpoint->snapshots()->create([
                'polled_at' => $polledAt,
                'success' => $fetch->success,
                'raw_size_bytes' => strlen($fetch->body),
                'duration_ms' => $fetch->durationMs,
                'retries' => $fetch->retries,
                'uptime_seconds' => $snapshotData->uptimeSeconds,
                'mo_total' => $snapshotData->moTotal,
                'mt_total' => $snapshotData->mtTotal,
                'mo_rate' => $moRate,
                'mt_rate' => $mtRate,
                'inbound_queue' => $snapshotData->inboundQueue,
                'outbound_queue' => $snapshotData->outboundQueue,
                'dlr_queue' => $snapshotData->dlrQueue,
                'received' => $snapshotData->received,
                'sent' => $snapshotData->sent,
                'failed_count' => $snapshotData->failedCount,
                'errors' => $snapshotData->errors,
                'box_id' => $snapshotData->boxId,
            ]);

            foreach ($smscs as $smsc) {
                $snapshot->smscMetrics()->create([
                    'endpoint_id' => $endpoint->id,
                    'smsc_name' => $smsc->smscName,
                    'smsc_id' => $smsc->smscId,
                    'smsc_state' => $smsc->state,
                    'latency_ms' => $smsc->latencyMs,
                    'mo_count' => $smsc->moCount,
                    'mt_count' => $smsc->mtCount,
                    'received' => $smsc->received,
                    'sent' => $smsc->sent,
                    'failed' => $smsc->failed,
                    'queued' => $smsc->queued,
                    'last_seen_at' => $smsc->lastSeenAt !== null
                        ? Carbon::instance($smsc->lastSeenAt)
                        : null,
                ]);
            }

            return $snapshot;
        });

        $this->updateEndpointHealth($endpoint, $fetch->success, $fetch->errorMessage, $polledAt);

        Log::channel('kannel')->info('kannel.poll.completed', [
            'endpoint_id' => $endpoint->id,
            'endpoint_name' => $endpoint->name,
            'snapshot_id' => $snapshot->id ?? null,
            'success' => $fetch->success,
            'retries' => $fetch->retries,
            'duration_ms' => $fetch->durationMs,
            'smsc_count' => count($smscs),
        ]);

        return $fetch->success;
    }

    private function updateEndpointHealth(KannelEndpoint $endpoint, bool $success, ?string $errorMessage, CarbonInterface $polledAt): void
    {
        if ($success) {
            $endpoint->consecutive_failures = 0;
            $endpoint->status = EndpointStatus::Healthy;
            $endpoint->last_polled_at = $polledAt;
            $endpoint->last_success_at = $polledAt;
            $endpoint->last_error = null;
            $endpoint->save();

            return;
        }

        $threshold = (int) config('kannel.consecutive_failures_before_unreachable', 3);
        $endpoint->consecutive_failures = (int) $endpoint->consecutive_failures + 1;
        $endpoint->last_polled_at = $polledAt;
        $endpoint->last_error = $errorMessage;

        if ($endpoint->consecutive_failures >= $threshold) {
            $endpoint->status = EndpointStatus::Unreachable;
        } else {
            $endpoint->status = EndpointStatus::Degraded;
        }

        $endpoint->save();
    }

    private function handleEndpointError(KannelEndpoint $endpoint, string $message): void
    {
        $threshold = (int) config('kannel.consecutive_failures_before_unreachable', 3);
        $endpoint->consecutive_failures = (int) $endpoint->consecutive_failures + 1;
        $endpoint->last_polled_at = now();
        $endpoint->last_error = $message;
        $endpoint->status = $endpoint->consecutive_failures >= $threshold
            ? EndpointStatus::Unreachable
            : EndpointStatus::Degraded;
        $endpoint->save();

        Log::channel('kannel')->error('kannel.poll.endpoint_exception', [
            'endpoint_id' => $endpoint->id,
            'message' => $message,
        ]);
    }
}
