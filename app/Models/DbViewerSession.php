<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One opening of a server's database in the viewer (Adminer).
 *
 * The browser gets a single-use ticket valid for a minute; the viewer redeems
 * it for a tunnel and the credentials of a MySQL user whose rights match the
 * person's role, so read-only is enforced by MySQL itself.
 */
class DbViewerSession extends Model
{
    public const MODE_READ = 'read';

    public const MODE_WRITE = 'write';

    /** The MySQL users the viewer signs in as, per mode. */
    public const MYSQL_USERS = [
        self::MODE_WRITE => 'cp_viewer_rw',
        self::MODE_READ => 'cp_viewer_ro',
    ];

    protected $fillable = [
        'server_id', 'user_id', 'mode', 'ip', 'ticket_hash', 'ticket_expires_at',
        'started_at', 'ended_at', 'end_reason',
    ];

    /** @var array<int, string> */
    protected $hidden = ['ticket_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * A new session and its plain ticket, which is never stored.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Server $server, User $user, string $mode, ?string $ip): array
    {
        $ticket = Str::random(48);

        $session = self::create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'mode' => $mode,
            'ip' => $ip,
            'ticket_hash' => hash('sha256', $ticket),
            'ticket_expires_at' => now()->addMinute(),
        ]);

        return [$session, $ticket];
    }

    public function mysqlUser(): string
    {
        return self::MYSQL_USERS[$this->mode];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
