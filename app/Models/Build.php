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
        'service_id', 'edition', 'number', 'stack_id', 'branch', 'commit_sha', 'version',
        'image_tag', 'digest', 'migration_kind', 'affected_tables',
        'migration_reasons', 'changelog', 'status', 'trigger', 'user_id', 'task_id',
        'error', 'log_path', 'built_at',
    ];

    public const TRIGGER_WEBHOOK = 'webhook';

    public const TRIGGER_MANUAL = 'manual';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'affected_tables' => 'array',
            'migration_reasons' => 'array',
            'changelog' => 'array',
            'number' => 'integer',
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The edition a branch builds: `edition/franchisee` builds "franchisee". */
    public static function editionFor(string $branch): ?string
    {
        return str_starts_with($branch, 'edition/') ? substr($branch, 8) : null;
    }

    /** What people call it: "Hiring #143 (a1b2c3d)", "Data/franchisee #12". */
    public function label(): string
    {
        $name = $this->service->name.($this->edition ? '/'.$this->edition : '');
        $number = $this->number ? ' #'.$this->number : '';
        $sha = $this->commit_sha ? ' ('.$this->shortSha().')' : '';

        return $name.$number.$sha;
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
        return substr((string) $this->commit_sha, 0, 7);
    }
}
