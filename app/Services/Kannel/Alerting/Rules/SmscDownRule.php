<?php

namespace App\Services\Kannel\Alerting\Rules;

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Enums\SMSCState;
use App\Models\KannelAlertRule;
use App\Models\KannelSmscMetric;

class SmscDownRule implements KannelAlertRuleStrategy
{
    public function evaluate(KannelAlertRule $rule, array $context): array
    {
        /** @var list<KannelSmscMetric> $smscs */
        $smscs = $context['latest_smscs'] ?? [];
        $endpoint = $context['endpoint_id'] ?? null;

        $results = [];
        $ruleEndpoint = $rule->endpoint_id;

        foreach ($smscs as $smsc) {
            if ($ruleEndpoint !== null && (int) $ruleEndpoint !== (int) $smsc->endpoint_id) {
                continue;
            }
            if ($endpoint !== null && (int) $endpoint !== (int) $smsc->endpoint_id) {
                continue;
            }

            $isDown = $smsc->smsc_state === SMSCState::Disconnected
                || $smsc->smsc_state === SMSCState::Unknown;

            if (! $isDown) {
                continue;
            }

            $results[] = new AlertEvaluationResult(
                triggered: true,
                ruleType: AlertRuleType::SmscDown,
                severity: $rule->severity instanceof AlertSeverity ? $rule->severity : AlertSeverity::from((string) $rule->severity),
                fingerprintKey: 'smsc:'.$smsc->smsc_name,
                message: sprintf('SMSC %s is in state %s on endpoint %d.', $smsc->smsc_name, $smsc->smsc_state?->value ?? 'unknown', $smsc->endpoint_id),
                endpointId: (int) $smsc->endpoint_id,
                smscName: $smsc->smsc_name,
                context: ['state' => $smsc->smsc_state?->value, 'latency_ms' => $smsc->latency_ms],
            );
        }

        return $results;
    }
}
