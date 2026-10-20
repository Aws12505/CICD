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
        'main_branch',
        'build_recipe',
        'dockerfile_path',
        'subdomain',
        'database_name',
        'auth_service_name',
        'extra_databases',
        'processes',
        'first_run_commands',
        'every_deploy_commands',
        'health_path',
        'per_stack_build',
        'identity_provider',
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
            'identity_provider' => 'boolean',
            'nats_publishes' => 'array',
            'nats_consumes' => 'array',
            'extra_databases' => 'array',
            'processes' => 'array',
            'first_run_commands' => 'array',
            'every_deploy_commands' => 'array',
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
     * Containers beside the web one, each from the same image.
     *
     * From the catalog's `processes` when set. A `laravel-worker` service
     * without them keeps the single supervisord worker its image starts
     * with the "worker" argument.
     *
     * A process may also listen: `port` is the container port and `path` the
     * URL prefix nginx routes to it on the service's domain (Reverb: 8080
     * and /app).
     *
     * @return list<array{name: string, command: string|null, port: int|null, path: string|null}>
     */
    public function processList(): array
    {
        $processes = collect($this->processes ?? [])
            ->filter(fn ($p) => filled($p['name'] ?? null) && filled($p['command'] ?? null))
            ->map(fn ($p) => [
                'name' => (string) $p['name'],
                'command' => (string) $p['command'],
                'port' => filled($p['port'] ?? null) ? (int) $p['port'] : null,
                'path' => filled($p['path'] ?? null) ? '/'.ltrim((string) $p['path'], '/') : null,
            ])
            ->values()
            ->all();

        if ($processes === [] && $this->runsAWorker()) {
            return [['name' => 'worker', 'command' => null, 'port' => null, 'path' => null]];
        }

        return $processes;
    }

    /**
     * Every compose service this one becomes: the web container and one per
     * process. A release swaps all of them together.
     *
     * @return list<string>
     */
    public function composeServices(): array
    {
        return [
            $this->slug,
            ...array_map(fn (array $p) => $this->slug.'_'.$p['name'], $this->processList()),
        ];
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
