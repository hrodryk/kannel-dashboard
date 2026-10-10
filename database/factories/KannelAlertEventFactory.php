<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Models\KannelAlertEvent;
use App\Models\KannelAlertRule;
use App\Models\KannelEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KannelAlertEvent>
 */
class KannelAlertEventFactory extends Factory
{
    protected $model = KannelAlertEvent::class;

    public function definition(): array
    {
        return [
            'rule_id' => KannelAlertRule::factory(),
            'endpoint_id' => KannelEndpoint::factory(),
            'smsc_name' => 'SMSC_A',
            'fingerprint' => '1:1:SMSC_A:smsc_down:default',
            'state' => 'firing',
            'severity' => AlertSeverity::Critical,
            'title' => 'SMSC Disconnected: SMSC_A',
            'message' => 'SMSC SMSC_A has been disconnected for 1 minute.',
            'payload' => ['smsc' => 'SMSC_A', 'state' => 'disconnected'],
            'fired_at' => now()->subMinutes(3),
            'resolved_at' => null,
            'last_notified_at' => now()->subMinutes(3),
            'notify_attempts' => 1,
        ];
    }

    public function resolved(): self
    {
        return $this->state(fn (): array => [
            'state' => 'resolved',
            'resolved_at' => now(),
        ]);
    }
}
