<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One browser terminal on a server or a container (decision 21).
 *
 * Created with a single-use token that the gateway redeems within a minute;
 * ends with an asciicast transcript of everything the session printed.
 */
class TerminalSession extends Model
{
    protected $fillable = [
        'server_id', 'user_id', 'container', 'token_hash', 'token_expires_at',
        'started_at', 'ended_at', 'end_reason', 'recording_path',
        'recording_bytes', 'recording_truncated', 'client_ip',
    ];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'recording_truncated' => 'boolean',
            'recording_bytes' => 'integer',
        ];
    }

    /**
     * A new session and its token. The token is returned once and only its
     * hash is kept.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Server $server, User $user, ?string $container, ?string $ip): array
    {
        $token = Str::random(64);

        $session = self::create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'container' => $container,
            'token_hash' => hash('sha256', $token),
            'token_expires_at' => now()->addSeconds((int) config('terminal.token_ttl', 60)),
            'client_ip' => $ip,
        ]);

        return [$session, $token];
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
