<?php

namespace App\Models;

use App\Services\Build\MigrationClassifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image, built once, from one commit.
 *
 * The same Build is deployed to every stack that should run it, which is what
 * makes "testing and live ran the same bytes" checkable rather than asserted.
 */
class Build extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id', 'edition', 'stack_id', 'branch', 'commit_sha', 'version',
        'image_tag', 'digest', 'migration_kind', 'affected_tables',
        'migration_reasons', 'status', 'error', 'log_path', 'built_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'affected_tables' => 'array',
            'migration_reasons' => 'array',
            'built_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function stack(): BelongsTo
    {
        return $this->belongsTo(Stack::class);
    }

    /** Whether deploying this needs a scheduled maintenance window. */
    public function needsWindow(): bool
    {
        return $this->migration_kind === MigrationClassifier::KIND_DESTRUCTIVE;
    }

    /** The full reference a client server pulls: pinned by digest when known. */
    public function reference(): string
    {
        $repository = $this->service->imageRepository();

        return filled($this->digest)
            ? $repository.'@'.$this->digest
            : $repository.':'.$this->image_tag;
    }

    public function shortSha(): string
    {
        return substr($this->commit_sha, 0, 7);
    }
}
