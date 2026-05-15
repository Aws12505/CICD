<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'pipeline_id',
        'status',
        'commit_sha',
        'commit_message',
        'pusher',
        'triggered_by',
        'started_at',
        'finished_at',
        'duration_ms',
        'log_path',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stepResults(): HasMany
    {
        return $this->hasMany(PipelineStepResult::class, 'run_id')->orderBy('step_index');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['success', 'failed', 'cancelled']);
    }
}
