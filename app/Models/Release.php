<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of service changes applied to one stack.
 *
 * Four kinds, one model, because they differ only in where the target digests
 * come from and what has to be recorded:
 *
 *   normal     deploy newly built images
 *   promotion  copy the digests testing is running to live — no rebuild
 *   rollback   re-point at digests a past release recorded
 *   emergency  straight to live, reason required, permanently marked
 */
class Release extends Model
{
    use HasFactory;

    public const KIND_NORMAL = 'normal';

    public const KIND_PROMOTION = 'promotion';

    public const KIND_ROLLBACK = 'rollback';

    public const KIND_EMERGENCY = 'emergency';

    protected $fillable = [
        'stack_id', 'kind', 'status', 'reason', 'source_release_id',
        'created_by', 'task_id', 'scheduled_for', 'window_started_at', 'window_ended_at',
        'started_at', 'finished_at', 'log_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'window_started_at' => 'datetime',
            'window_ended_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function stack(): BelongsTo
    {
        return $this->belongsTo(Stack::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReleaseItem::class)->orderBy('position');
    }

    public function sourceRelease(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_release_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEmergency(): bool
    {
        return $this->kind === self::KIND_EMERGENCY;
    }

    /** Whether any item in this release will run a destructive migration. */
    public function needsWindow(): bool
    {
        return $this->items->contains(fn (ReleaseItem $item) => $item->build?->needsWindow() ?? false);
    }

    /** Tables the pre-migration dump has to cover. */
    public function affectedTables(): array
    {
        return $this->items
            ->flatMap(fn (ReleaseItem $item) => $item->build?->affected_tables ?? [])
            ->unique()
            ->values()
            ->all();
    }
}
