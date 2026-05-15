<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineStepResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'step_index',
        'name',
        'command',
        'status',
        'exit_code',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'exit_code' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class, 'run_id');
    }
}
