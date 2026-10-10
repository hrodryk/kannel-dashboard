<?php

namespace Database\Factories;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Models\KannelAlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KannelAlertRule>
 */
class KannelAlertRuleFactory extends Factory
{
    protected $model = KannelAlertRule::class;

    public function definition(): array
    {
        return [
            'endpoint_id' => null,
            'name' => 'SMSC Disconnection Alert',
            'type' => AlertRuleType::SmscDown,
            'severity' => AlertSeverity::Critical,
            'threshold' => ['max_disconnected_minutes' => 1],
            'cooldown_minutes' => 15,
            'channels' => [
                ['type' => 'mail', 'emails' => ['ops@example.test']],
            ],
            'enabled' => true,
            'description' => 'Trigger when any SMSC is reported disconnected.',
        ];
    }

    public function queueExceeded(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Queue Exceeded',
            'type' => AlertRuleType::QueueExceeded,
            'severity' => AlertSeverity::Warning,
            'threshold' => ['outbound_queue' => 1000],
        ]);
    }

    public function throughputDrop(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Throughput Drop',
            'type' => AlertRuleType::ThroughputDrop,
            'severity' => AlertSeverity::Warning,
            'threshold' => ['fraction' => 0.5],
        ]);
    }
}
