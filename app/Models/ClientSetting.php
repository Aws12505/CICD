<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Config: the tier of client difference that takes effect immediately.
 *
 * Read at runtime by the services themselves. Changing one of these is not a
 * release — no build, no deploy, no containers move. That is the whole point:
 * most of what a client asks for should land here.
 */
class ClientSetting extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'service_id', 'key', 'value', 'description'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array'];
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
