<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entitlement: whether one client's stack runs one service, and what it is
 * currently running.
 *
 * Turning `enabled` on is a release event — containers appear, a database is
 * created, a certificate is issued. It is not a display toggle.
 */
class StackService extends Model
{
    use HasFactory;

    protected $fillable = [
        'stack_id',
        'service_id',
        'enabled',
        'edition',
        'host_port',
        'process_ports',
        'current_digest',
        'desired_digest',
        'state',
        'last_deployed_at',
        'initialized_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'host_port' => 'integer',
            'process_ports' => 'array',
            'last_deployed_at' => 'datetime',
            'initialized_at' => 'datetime',
        ];
    }

    public function stack(): BelongsTo
    {
        return $this->belongsTo(Stack::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** The container name on the host, e.g. "acme_live_hiring". */
    public function containerName(): string
    {
        return $this->stack->compose_project.'_'.$this->service->slug;
    }

    /**
     * A process container's name, e.g. "acme_live_hiring_queue". The compose
     * template, the drift check and the services' own settings all use this.
     */
    public function processContainerName(string $process): string
    {
        return $this->containerName().'_'.$process;
    }

    /**
     * Whether what is running matches what we asked for.
     *
     * Compared on digest rather than tag, because a tag can be repointed at
     * different bytes and a digest cannot.
     */
    public function hasDrifted(): bool
    {
        return filled($this->desired_digest)
            && filled($this->current_digest)
            && $this->desired_digest !== $this->current_digest;
    }

    public function runsTemplate(): bool
    {
        return blank($this->edition);
    }
}
