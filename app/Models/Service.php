<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One deployable service, defined here rather than by a manifest in its repo.
 *
 * That choice means a pull request can add an environment variable this table
 * does not know about. The boot-time env check is what catches it, before
 * anything swaps.
 */
class Service extends Model
{
    use HasFactory;

    public const RECIPE_LARAVEL_API = 'laravel-api';

    public const RECIPE_LARAVEL_WORKER = 'laravel-worker';

    public const RECIPE_NEXTJS = 'nextjs';

    protected $fillable = [
        'name',
        'slug',
        'repo_full_name',
        'default_branch',
        'build_recipe',
        'dockerfile_path',
        'subdomain',
        'database_name',
        'health_path',
        'per_stack_build',
        'nats_publishes',
        'nats_consumes',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'per_stack_build' => 'boolean',
            'nats_publishes' => 'array',
            'nats_consumes' => 'array',
            'position' => 'integer',
        ];
    }

    public function envDefinitions(): HasMany
    {
        return $this->hasMany(ServiceEnvDefinition::class)->orderBy('key');
    }

    public function stackServices(): HasMany
    {
        return $this->hasMany(StackService::class);
    }

    public function runsAWorker(): bool
    {
        return $this->build_recipe === self::RECIPE_LARAVEL_WORKER;
    }

    /**
     * The repository path this service's images are published under.
     *
     * Never contains a client's name. A per-stack build is distinguished by
     * the stack id in the tag, not by the repository.
     */
    public function imageRepository(): string
    {
        return rtrim(config('deploy.registry'), '/').'/'.$this->slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
