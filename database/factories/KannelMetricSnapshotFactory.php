<?php

namespace Database\Factories;

use App\Models\KannelEndpoint;
use App\Models\KannelMetricSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KannelMetricSnapshot>
 */
class KannelMetricSnapshotFactory extends Factory
{
    protected $model = KannelMetricSnapshot::class;

    public function definition(): array
    {
        $moTotal = fake()->randomNumber(7);
        $mtTotal = fake()->randomNumber(7);

        return [
            'endpoint_id' => KannelEndpoint::factory(),
            'polled_at' => now()->subSeconds(30),
            'success' => true,
            'raw_size_bytes' => fake()->randomNumber(3),
            'duration_ms' => fake()->randomNumber(2),
            'retries' => 0,
            'uptime_seconds' => fake()->randomNumber(6),
            'mo_total' => $moTotal,
            'mt_total' => $mtTotal,
            'mo_rate' => round(fake()->randomFloat(3, 0.5, 200), 4),
            'mt_rate' => round(fake()->randomFloat(3, 1, 500), 4),
            'inbound_queue' => fake()->randomNumber(3),
            'outbound_queue' => fake()->randomNumber(3),
            'dlr_queue' => fake()->randomNumber(2),
            'received' => $moTotal,
            'sent' => $mtTotal,
            'failed_count' => fake()->randomNumber(2),
            'errors' => [
                ['code' => '0x00000008', 'count' => 5, 'description' => 'System error'],
            ],
            'box_id' => 'kannel-'.fake()->word(),
        ];
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'success' => false,
            'duration_ms' => 10000,
            'retries' => 3,
            'mo_total' => 0,
            'mt_total' => 0,
            'errors' => [['code' => 'HTTP_ERROR', 'count' => 1, 'description' => 'cURL 28 timeout']],
        ]);
    }
}
