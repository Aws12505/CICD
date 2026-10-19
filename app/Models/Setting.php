<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Control-plane-wide settings: GitHub and registry tokens, the address the
 * firewall should allow SSH from, the email certificates are issued to.
 *
 * Every value is encrypted, because most of them are credentials and the
 * ones that are not cost nothing to encrypt.
 */
class Setting extends Model
{
    public const GITHUB_TOKEN = 'github_token';

    public const GITHUB_WEBHOOK_SECRET = 'github_webhook_secret';

    public const REGISTRY_USERNAME = 'registry_username';

    /** Push access, used only on the control plane when building. */
    public const REGISTRY_PUSH_TOKEN = 'registry_push_token';

    /** Read-only package access, installed on every client server. */
    public const REGISTRY_PULL_TOKEN = 'registry_pull_token';

    /**
     * Extra addresses client firewalls allow SSH from, space-separated. The
     * control plane's own address is always allowed, read from the live
     * connection during setup.
     */
    public const CONTROL_PLANE_IP = 'control_plane_ip';

    public const ACME_EMAIL = 'acme_email';

    protected $fillable = ['key', 'value'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = static::query()->where('key', $key)->first()?->value;

        return filled($value) ? $value : $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function has(string $key): bool
    {
        return filled(static::get($key));
    }
}
