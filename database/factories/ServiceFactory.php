<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = $this->faker->unique()->word();

        return [
            'name' => ucfirst($slug),
            'slug' => $slug,
            'repo_full_name' => 'RnD-Experts-Team/'.ucfirst($slug),
            'default_branch' => 'testing',
            'build_recipe' => Service::RECIPE_LARAVEL_API,
            'subdomain' => $slug,
            'database_name' => $slug,
            'health_path' => '/up',
            'per_stack_build' => false,
        ];
    }

    /** The dashboard: its client-specific values are frozen at build time. */
    public function perStackBuild(): static
    {
        return $this->state(fn () => [
            'build_recipe' => Service::RECIPE_NEXTJS,
            'per_stack_build' => true,
            'database_name' => null,
        ]);
    }
}
