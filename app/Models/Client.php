<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'domain', 'admin_email', 'admin_name', 'status', 'notes'];

    public function stacks(): HasMany
    {
        return $this->hasMany(Stack::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(ClientSetting::class);
    }

    public function liveStack(): ?Stack
    {
        return $this->stacks->firstWhere('tier', Stack::TIER_LIVE);
    }

    public function testingStack(): ?Stack
    {
        return $this->stacks->firstWhere('tier', Stack::TIER_TESTING);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
