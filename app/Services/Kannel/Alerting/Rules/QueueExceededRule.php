<?php

namespace App\Services\Kannel\Alerting\Rules;

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Models\KannelAlertRule;
use App\Models\KannelMetricSnapshot;

class QueueExceededRule implements KannelAlertRuleStrategy
{
    public function evaluate(KannelAlertRule $rule, array $context): array
    {
        /** @var list<KannelMetricSnapshot> $snapshots */
        $snapshots = $context['latest_snapshots'] ?? [];
        $threshold = (int) ($rule->threshold['outbound_queue'] ?? $rule->threshold['any_queue'] ?? 1000);
        $endpointId = $rule->endpoint_id;
        $severity = $rule->severity instanceof AlertSeverity ? $rule->severity : AlertSeverity::from((string) $rule->severity);

        $results = [];
        foreach ($snapshots as $snapshot) {
            if ($endpointId !== null && (int) $endpointId !== (int) $snapshot->endpoint_id) {
                continue;
            }

            $worst = max($snapshot->inbound_queue, $snapshot->outbound_queue, $snapshot->dlr_queue);
            if ($worst < $threshold) {
                continue;
            }

            $results[] = new AlertEvaluationResult(
                triggered: true,
                ruleType: AlertRuleType::QueueExceeded,
                severity: $severity,
                fingerprintKey: 'queue:'.$snapshot->endpoint_id,
                message: sprintf(
                    'Endpoint %d queue length %d exceeded threshold %d (in %d / out %d / dlr %d).',
                    $snapshot->endpoint_id,
                    $worst,
                    $threshold,
                    $snapshot->inbound_queue,
                    $snapshot->outbound_queue,
                    $snapshot->dlr_queue,
                ),
                endpointId: (int) $snapshot->endpoint_id,
                smscName: null,
                context: [
                    'inbound_queue' => $snapshot->inbound_queue,
                    'outbound_queue' => $snapshot->outbound_queue,
                    'dlr_queue' => $snapshot->dlr_queue,
                    'threshold' => $threshold,
                ],
            );
        }

        return $results;
    }
}
