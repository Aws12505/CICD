<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service's move within a release.
 *
 * from_digest and to_digest are written before anything runs. That ordering
 * is the whole reason a revert is possible: if the "from" were recorded after
 * the swap, a crash mid-deploy would leave nothing to go back to.
 */
class ReleaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'release_id', 'service_id', 'build_id', 'from_digest', 'to_digest',
        'position', 'status', 'error', 'started_at', 'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }

    /** A succeeded item can be put back on its own, without the whole release. */
    public function isRevertable(): bool
    {
        return $this->status === 'succeeded' && filled($this->from_digest);
    }
}
