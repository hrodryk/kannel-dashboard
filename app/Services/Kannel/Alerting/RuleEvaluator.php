<?php

namespace App\Services\Kannel\Alerting;

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Enums\AlertRuleType;
use App\Models\KannelAlertRule;
use App\Services\Kannel\Alerting\Rules\EndpointUnreachableRule;
use App\Services\Kannel\Alerting\Rules\KannelAlertRuleStrategy;
use App\Services\Kannel\Alerting\Rules\QueueExceededRule;
use App\Services\Kannel\Alerting\Rules\SmscDownRule;
use App\Services\Kannel\Alerting\Rules\ThroughputDropRule;
use InvalidArgumentException;

class RuleEvaluator
{
    /**
     * @var array<string, KannelAlertRuleStrategy>
     */
    private array $strategies;

    public function __construct(
        SmscDownRule $smscDown,
        QueueExceededRule $queueExceeded,
        ThroughputDropRule $throughputDrop,
        EndpointUnreachableRule $endpointUnreachable,
    ) {
        $this->strategies = [
            AlertRuleType::SmscDown->value => $smscDown,
            AlertRuleType::QueueExceeded->value => $queueExceeded,
            AlertRuleType::ThroughputDrop->value => $throughputDrop,
            AlertRuleType::EndpointUnreachable->value => $endpointUnreachable,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<AlertEvaluationResult>
     */
    public function evaluate(KannelAlertRule $rule, array $context): array
    {
        $type = $rule->type instanceof AlertRuleType ? $rule->type->value : (string) $rule->type;

        if (! isset($this->strategies[$type])) {
            throw new InvalidArgumentException("Unsupported alert rule type: {$type}");
        }

        return $this->strategies[$type]->evaluate($rule, $context);
    }
}
