<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Server;
use App\Models\Stack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stack>
 */
class StackFactory extends Factory
{
    protected $model = Stack::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'server_id' => Server::factory(),
            'tier' => Stack::TIER_LIVE,
            'compose_project' => $this->faker->unique()->slug(2),
            'port_range_start' => $this->faker->unique()->numberBetween(400, 599) * 100,
            'status' => 'active',
        ];
    }

    public function testing(): static
    {
        return $this->state(fn () => ['tier' => Stack::TIER_TESTING]);
    }
}
