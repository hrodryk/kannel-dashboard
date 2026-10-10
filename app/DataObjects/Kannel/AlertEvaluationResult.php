<?php

namespace App\DataObjects\Kannel;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;

final readonly class AlertEvaluationResult
{
    /**
     * @param  array<string, int|string|float>  $context
     */
    public function __construct(
        public bool $triggered,
        public AlertRuleType $ruleType,
        public AlertSeverity $severity,
        public string $fingerprintKey,
        public string $message,
        public ?int $endpointId = null,
        public ?string $smscName = null,
        public array $context = [],
    ) {}

    public function fingerprint(int $ruleId): string
    {
        return sprintf(
            '%d:%s:%s:%s:%s',
            $ruleId,
            $this->endpointId ?? '0',
            $this->smscName ?? '',
            $this->ruleType->value,
            $this->fingerprintKey,
        );
    }
}
