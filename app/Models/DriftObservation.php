<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A moment where a server was running something we did not ask for.
 */
class DriftObservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'stack_id', 'service_id', 'expected_digest', 'observed_digest',
        'observed_at', 'resolution', 'resolution_reason', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function isOpen(): bool
    {
        return $this->resolution === null;
    }

    /** Nothing is running at all — a container that died or was removed. */
    public function isMissing(): bool
    {
        return blank($this->observed_digest);
    }
}
