<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An environment value for both tiers of one client.
 *
 * The layer under a stack's own values: a vendor credential, a feature flag
 * or a label is usually the same on testing and live, so it is set once here
 * and overridden per tier only where they differ. A null service_id applies
 * to every service.
 *
 * No build and no release: saving and clicking Apply restarts the containers
 * that read it. Encrypted whether or not it is marked secret, as stack values
 * are.
 */
class ClientSetting extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'service_id', 'key', 'value', 'is_secret', 'description'];

    protected $hidden = ['value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isGlobal(): bool
    {
        return $this->service_id === null;
    }
}
