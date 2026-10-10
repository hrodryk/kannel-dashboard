<?php

namespace Database\Factories;

use App\Models\KannelEndpoint;
use App\Models\KannelHourlyRollup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KannelHourlyRollup>
 */
class KannelHourlyRollupFactory extends Factory
{
    protected $model = KannelHourlyRollup::class;

    public function definition(): array
    {
        $moStart = fake()->randomNumber(7);
        $mtStart = fake()->randomNumber(7);
        $moEnd = $moStart + fake()->randomNumber(4);
        $mtEnd = $mtStart + fake()->randomNumber(4);

        return [
            'endpoint_id' => KannelEndpoint::factory(),
            'smsc_name' => null,
            'bucket_hour' => now()->startOfHour(),
            'mo_total_start' => $moStart,
            'mo_total_end' => $moEnd,
            'mt_total_start' => $mtStart,
            'mt_total_end' => $mtEnd,
            'mo_volume' => $moEnd - $moStart,
            'mt_volume' => $mtEnd - $mtStart,
            'avg_mo_rate' => fake()->randomFloat(3, 0.1, 200),
            'avg_mt_rate' => fake()->randomFloat(3, 0.1, 500),
            'peak_mo_rate' => fake()->randomFloat(3, 10, 300),
            'peak_mt_rate' => fake()->randomFloat(3, 10, 600),
            'avg_inbound_queue' => fake()->randomNumber(3),
            'avg_outbound_queue' => fake()->randomNumber(3),
            'peak_inbound_queue' => fake()->randomNumber(4),
            'peak_outbound_queue' => fake()->randomNumber(4),
            'failed_count' => fake()->randomNumber(2),
            'samples' => 120,
        ];
    }
}
