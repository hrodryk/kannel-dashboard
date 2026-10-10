<?php

namespace Database\Factories;

use App\Enums\EndpointStatus;
use App\Models\KannelEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KannelEndpoint>
 */
class KannelEndpointFactory extends Factory
{
    protected $model = KannelEndpoint::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Kannel',
            'url' => fake()->randomElement(['http', 'https']).'://'.fake()->domainName().':13000',
            'admin_user' => 'kannel',
            'admin_password' => 'secret-password',
            'poll_interval_seconds' => 30,
            'timeout_seconds' => 10,
            'consecutive_failures' => 0,
            'status' => EndpointStatus::Healthy,
            'enabled' => true,
            'last_polled_at' => now()->subMinutes(1),
            'last_success_at' => now()->subMinutes(1),
            'last_error' => null,
        ];
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => [
            'enabled' => false,
            'status' => EndpointStatus::Disabled,
        ]);
    }

    public function unreachable(): self
    {
        return $this->state(fn (): array => [
            'consecutive_failures' => 5,
            'status' => EndpointStatus::Unreachable,
            'last_error' => 'Connection refused',
        ]);
    }
}
