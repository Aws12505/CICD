<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One client's deployment on one tier: (client, tier, server).
 *
 * This is the unit everything deploys to. Both tiers currently point at the
 * same server; moving testing to its own machine is a change of server_id.
 */
class Stack extends Model
{
    use HasFactory;

    public const TIER_LIVE = 'live';

    public const TIER_TESTING = 'testing';

    protected $fillable = [
        'client_id',
        'tier',
        'server_id',
        'compose_project',
        'port_range_start',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['port_range_start' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(StackService::class);
    }

    public function envValues(): HasMany
    {
        return $this->hasMany(StackEnvValue::class);
    }

    public function isLive(): bool
    {
        return $this->tier === self::TIER_LIVE;
    }

    /**
     * Appended to subdomains and database names so the two tiers on one
     * server never collide. Live gets nothing, matching the existing estate
     * where hiring.lcportal.cloud is live and hiringtesting.* is not.
     */
    public function suffix(): string
    {
        return $this->isLive() ? '' : 'testing';
    }

    /** e.g. "hiringtesting.acme.cloud" */
    public function domainFor(Service $service): string
    {
        return $service->subdomain.$this->suffix().'.'.$this->client->domain;
    }

    /** e.g. "hiringpizza_testing" */
    public function databaseFor(Service $service): ?string
    {
        if (blank($service->database_name)) {
            return null;
        }

        return $service->database_name.($this->isLive() ? '' : '_testing');
    }

    /**
     * Redis database index. Distinct per tier so a flush on one cannot empty
     * the other; the shared Redis is further constrained by an ACL user.
     */
    public function redisDatabase(): int
    {
        return $this->isLive() ? 0 : 1;
    }

    /** Human label used in logs and the UI. */
    public function label(): string
    {
        return $this->client->name.' · '.$this->tier;
    }
}
