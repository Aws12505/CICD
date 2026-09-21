<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One environment value for one stack.
 *
 * A null service_id means the value applies to every service on the stack.
 *
 * Values are encrypted regardless of whether they are marked secret: a stack
 * env value is a database password or a service token far more often than it
 * is anything harmless, and guessing wrong in the other direction is cheap.
 */
class StackEnvValue extends Model
{
    use HasFactory;

    protected $fillable = ['stack_id', 'service_id', 'key', 'value', 'is_secret'];

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

    public function stack(): BelongsTo
    {
        return $this->belongsTo(Stack::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function appliesToEveryService(): bool
    {
        return $this->service_id === null;
    }

    /** What is safe to show in a list: secrets are never rendered. */
    public function displayValue(): string
    {
        return $this->is_secret ? '••••••••' : (string) $this->value;
    }
}
