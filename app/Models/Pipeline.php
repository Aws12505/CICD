<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'branch',
        'steps',
        'enabled',
    ];

    protected $casts = [
        'steps' => 'array',
        'enabled' => 'boolean',
    ];

    protected $hidden = [];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PipelineRun::class);
    }

    public function latestRun()
    {
        return $this->hasOne(PipelineRun::class)->latestOfMany();
    }
}
