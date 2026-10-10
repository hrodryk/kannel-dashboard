<?php

namespace App\Services\Kannel\Alerting\Rules;

use App\DataObjects\Kannel\AlertEvaluationResult;
use App\Models\KannelAlertRule;

interface KannelAlertRuleStrategy
{
    /**
     * @param  array<string, mixed>  $context
     * @return list<AlertEvaluationResult>
     */
    public function evaluate(KannelAlertRule $rule, array $context): array;
}
