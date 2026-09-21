<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A restore that somebody performed.
 *
 * Always manual and always recorded: a restore discards everything written
 * since the backup was taken, so who did it and why is not optional.
 */
class BackupRestore extends Model
{
    use HasFactory;

    protected $fillable = [
        'backup_id', 'stack_id', 'performed_by', 'reason',
        'status', 'error', 'log_path', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
