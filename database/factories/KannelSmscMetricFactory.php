<?php

namespace Database\Factories;

use App\Enums\SMSCState;
use App\Models\KannelEndpoint;
use App\Models\KannelMetricSnapshot;
use App\Models\KannelSmscMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KannelSmscMetric>
 */
class KannelSmscMetricFactory extends Factory
{
    protected $model = KannelSmscMetric::class;

    public function definition(): array
    {
        return [
            'snapshot_id' => KannelMetricSnapshot::factory(),
            'endpoint_id' => KannelEndpoint::factory(),
            'smsc_name' => 'SMSC_'.fake()->randomLetter().fake()->randomLetter(),
            'smsc_id' => 'smpp_'.fake()->randomNumber(2),
            'smsc_state' => SMSCState::Connected,
            'latency_ms' => fake()->randomNumber(2),
            'mo_count' => fake()->randomNumber(5),
            'mt_count' => fake()->randomNumber(5),
            'received' => fake()->randomNumber(5),
            'sent' => fake()->randomNumber(5),
            'failed' => fake()->randomNumber(2),
            'queued' => fake()->randomNumber(3),
            'last_seen_at' => now(),
        ];
    }

    public function disconnected(): self
    {
        return $this->state(fn (): array => [
            'smsc_state' => SMSCState::Disconnected,
            'latency_ms' => 0,
        ]);
    }
}
