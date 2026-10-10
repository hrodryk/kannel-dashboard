<?php

namespace App\Services\Kannel\Alerting\Rules;

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Enums\EndpointStatus;
use App\Models\KannelAlertRule;
use App\Models\KannelEndpoint;

class EndpointUnreachableRule implements KannelAlertRuleStrategy
{
    public function evaluate(KannelAlertRule $rule, array $context): array
    {
        /** @var list<KannelEndpoint> $endpoints */
        $endpoints = $context['endpoints'] ?? [];
        $ruleEndpoint = $rule->endpoint_id;
        $threshold = (int) ($rule->threshold['consecutive_failures'] ?? config('kannel.consecutive_failures_before_unreachable', 3));
        $severity = $rule->severity instanceof AlertSeverity ? $rule->severity : AlertSeverity::from((string) $rule->severity);

        $results = [];
        foreach ($endpoints as $endpoint) {
            if ($ruleEndpoint !== null && (int) $ruleEndpoint !== (int) $endpoint->id) {
                continue;
            }
            if (! $endpoint->enabled) {
                continue;
            }

            $unreachable = $endpoint->status === EndpointStatus::Unreachable
                || (int) $endpoint->consecutive_failures >= $threshold;

            if (! $unreachable) {
                continue;
            }

            $results[] = new AlertEvaluationResult(
                triggered: true,
                ruleType: AlertRuleType::EndpointUnreachable,
                severity: $severity,
                fingerprintKey: 'unreachable:'.$endpoint->id,
                message: sprintf(
                    'Kannel endpoint %s (%d) is unreachable after %d consecutive failures: %s',
                    $endpoint->name,
                    $endpoint->id,
                    (int) $endpoint->consecutive_failures,
                    $endpoint->last_error ?? 'no detail',
                ),
                endpointId: (int) $endpoint->id,
                context: [
                    'consecutive_failures' => (int) $endpoint->consecutive_failures,
                    'last_error' => $endpoint->last_error,
                ],
            );
        }

        return $results;
    }
}
