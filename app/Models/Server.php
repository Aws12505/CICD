<?php

namespace App\Models;

use App\Models\Concerns\HasGeneratedSecrets;
use App\Targets\SshTarget;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A machine the control plane can reach over SSH.
 *
 * Holds the private key that grants root on a client's server, so the key is
 * encrypted at rest and hidden from every serialization by default.
 */
class Server extends Model
{
    use HasFactory, HasGeneratedSecrets;

    protected $fillable = [
        'name',
        'hostname',
        'ip_address',
        'ssh_user',
        'ssh_port',
        'ssh_private_key',
        'public_key',
        'host_key',
        'status',
        'last_seen_at',
        'last_error',
        'notes',
        'capabilities',
        'certificates',
        'provisioned_at',
    ];

    /**
     * Never let the key reach a response by accident. Anything that genuinely
     * needs it asks for the attribute explicitly.
     */
    protected $hidden = [
        'ssh_private_key',
        'host_key',
        'secrets',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ssh_private_key' => 'encrypted',
            'secrets' => 'encrypted:array',
            'capabilities' => 'array',
            'certificates' => 'array',
            'ssh_port' => 'integer',
            'last_seen_at' => 'datetime',
            'provisioned_at' => 'datetime',
        ];
    }

    public function stacks(): HasMany
    {
        return $this->hasMany(Stack::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    /** The line to paste into the VPS so the control plane can log in. */
    public function authorizeCommand(): ?string
    {
        if (blank($this->public_key)) {
            return null;
        }

        return 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && '
            ."echo '".trim($this->public_key)."' >> ~/.ssh/authorized_keys && "
            .'chmod 600 ~/.ssh/authorized_keys';
    }

    public function isProvisioned(): bool
    {
        return $this->provisioned_at !== null;
    }

    /**
     * A Target that runs commands on this server.
     *
     * @param  bool  $trustOnFirstUse  accept an unrecorded host key. Only the
     *                                 host-key capture flow should pass true:
     *                                 everything else must pin.
     */
    public function target(bool $trustOnFirstUse = false): SshTarget
    {
        return new SshTarget(
            host: $this->hostname,
            user: $this->ssh_user,
            port: $this->ssh_port,
            privateKey: (string) $this->ssh_private_key,
            hostKey: $trustOnFirstUse ? null : $this->host_key,
        );
    }

    public function isVerified(): bool
    {
        return filled($this->host_key);
    }

    public function markOnline(): void
    {
        $this->forceFill([
            'status' => 'online',
            'last_seen_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function markUnreachable(?string $reason): void
    {
        $this->forceFill([
            'status' => 'unreachable',
            'last_error' => $reason,
        ])->save();
    }
}
