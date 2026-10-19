<?php

namespace App\Models;

use App\Events\TaskLogLine;
use App\Events\TaskStatusChanged;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One long-running action and its live log.
 *
 * Every line written here is appended to a file and broadcast on `task.{id}`,
 * so the browser can watch a server being set up or a release running as it
 * happens, and come back to the full log afterwards.
 */
class Task extends Model
{
    public const TYPE_PROVISION = 'provision';

    public const TYPE_BUILD = 'build';

    public const TYPE_RELEASE = 'release';

    public const TYPE_APPLY = 'apply';

    public const TYPE_BACKUP = 'backup';

    public const TYPE_RESTORE = 'restore';

    public const TYPE_COMMAND = 'command';

    protected $fillable = [
        'type', 'title', 'subject_type', 'subject_id', 'status', 'user_id',
        'log_path', 'error', 'meta', 'started_at', 'finished_at',
    ];

    /**
     * Values that must never reach the log, however a command echoes them.
     * Held in memory only, for the life of this instance.
     *
     * @var list<string>
     */
    private array $masks = [];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function begin(string $type, string $title, ?Model $subject = null, ?User $user = null, array $meta = []): self
    {
        return self::create([
            'type' => $type,
            'title' => $title,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'user_id' => $user?->id,
            'status' => 'queued',
            'log_path' => 'logs/tasks/'.now()->format('Y/m/d').'/'.Str::uuid().'.log',
            'meta' => $meta ?: null,
        ]);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Never write these to the log. For passwords and tokens a command might
     * echo back.
     */
    public function mask(?string ...$secrets): self
    {
        foreach ($secrets as $secret) {
            if ($secret !== null && strlen($secret) >= 6) {
                $this->masks[] = $secret;
            }
        }

        return $this;
    }

    public function log(string $line): void
    {
        $line = str_replace($this->masks, '******', $line);

        $path = storage_path($this->log_path);
        File::ensureDirectoryExists(dirname($path));
        File::append($path, $line);

        try {
            broadcast(new TaskLogLine($this->id, $line));
        } catch (Throwable $e) {
            // A broadcast failure must never stop a deploy halfway.
            Log::warning('Task log broadcast failed', ['task' => $this->id, 'error' => $e->getMessage()]);
        }
    }

    /** A callback for CommandRunner's onOutput. */
    public function logger(): Closure
    {
        return fn (string $chunk) => $this->log($chunk);
    }

    /** A heading line, so a long log reads as sections. */
    public function step(string $title): void
    {
        $this->log("\n==> {$title}\n");
    }

    public function start(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
        $this->announce();
    }

    public function succeed(?string $message = null): void
    {
        if ($message !== null) {
            $this->log("\nOK: {$message}\n");
        }

        $this->update(['status' => 'succeeded', 'finished_at' => now()]);
        $this->announce();
    }

    public function fail(string $reason): void
    {
        $this->log("\nFAILED: {$reason}\n");
        $this->update(['status' => 'failed', 'error' => $reason, 'finished_at' => now()]);
        $this->announce();
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }

    public function contents(): string
    {
        $path = storage_path($this->log_path);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function announce(): void
    {
        try {
            broadcast(new TaskStatusChanged($this->id, $this->status));
        } catch (Throwable $e) {
            Log::warning('Task status broadcast failed', ['task' => $this->id, 'error' => $e->getMessage()]);
        }
    }
}
