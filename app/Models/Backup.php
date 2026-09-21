<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One backup, recorded in the database rather than inferred from disk.
 *
 * The old command left files in a directory and nothing else. Nothing could
 * answer "when did this stack last back up successfully?", which is exactly
 * the question that matters, because the normal way a backup system fails is
 * silently.
 */
class Backup extends Model
{
    use HasFactory;

    public const KIND_DATABASE = 'database';

    public const KIND_FILES = 'files';

    public const KIND_CONTROL_PLANE = 'control_plane';

    protected $fillable = [
        'stack_id', 'service_id', 'kind', 'status', 'triggered_by', 'release_id',
        'path', 'size_bytes', 'checksum_sha256', 'tables',
        'started_at', 'finished_at', 'retained_until', 'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tables' => 'array',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'retained_until' => 'datetime',
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

    public function restores(): HasMany
    {
        return $this->hasMany(BackupRestore::class);
    }

    public function isControlPlane(): bool
    {
        return $this->kind === self::KIND_CONTROL_PLANE;
    }
}
