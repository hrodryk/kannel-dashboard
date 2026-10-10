<?php

namespace App\Services\Kannel\Alerting\Rules;

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Models\KannelAlertRule;
use App\Models\KannelHourlyRollup;
use App\Models\KannelMetricSnapshot;
use Illuminate\Support\Carbon;

class ThroughputDropRule implements KannelAlertRuleStrategy
{
    public function evaluate(KannelAlertRule $rule, array $context): array
    {
        /** @var list<KannelMetricSnapshot> $snapshots */
        $snapshots = $context['latest_snapshots'] ?? [];
        $endpointId = $rule->endpoint_id;
        $fraction = (float) ($rule->threshold['fraction'] ?? config('kannel.throughput.drop_threshold_fraction', 0.5));
        $severity = $rule->severity instanceof AlertSeverity ? $rule->severity : AlertSeverity::from((string) $rule->severity);
        $baselineHours = (int) ($rule->threshold['baseline_hours'] ?? config('kannel.throughput.baseline_hours', 24));
        $rateWindow = max(1, (int) ($rule->threshold['rate_window_minutes'] ?? config('kannel.throughput.rate_window_minutes', 5)));

        $results = [];
        foreach ($snapshots as $snapshot) {
            if ($endpointId !== null && (int) $endpointId !== (int) $snapshot->endpoint_id) {
                continue;
            }

            $cutoff = Carbon::instance($snapshot->polled_at)->subHours($baselineHours);
            /** @var float $baselineMtRate */
            $baselineMtRate = (float) KannelHourlyRollup::query()
                ->where('endpoint_id', $snapshot->endpoint_id)
                ->whereNull('smsc_name')
                ->whereBetween('bucket_hour', [$cutoff, $snapshot->polled_at])
                ->avg('avg_mt_rate');
            if (! is_numeric($baselineMtRate)) {
                $baselineMtRate = 0.0;
            }

            $currentMtRate = (float) $snapshot->mt_rate;
            if ($baselineMtRate <= 0) {
                continue;
            }

            if ($currentMtRate / $baselineMtRate >= $fraction) {
                continue;
            }

            $results[] = new AlertEvaluationResult(
                triggered: true,
                ruleType: AlertRuleType::ThroughputDrop,
                severity: $severity,
                fingerprintKey: 'throughput:'.$snapshot->endpoint_id,
                message: sprintf(
                    'Endpoint %d MT throughput %.2f msg/s dropped below %.0f%% of baseline %.2f msg/s (window %d min).',
                    $snapshot->endpoint_id,
                    $currentMtRate,
                    $fraction * 100,
                    $baselineMtRate,
                    $rateWindow,
                ),
                endpointId: (int) $snapshot->endpoint_id,
                context: [
                    'current_mt_rate' => $currentMtRate,
                    'baseline_mt_rate' => $baselineMtRate,
                    'fraction' => $fraction,
                ],
            );
        }

        return $results;
    }
}
