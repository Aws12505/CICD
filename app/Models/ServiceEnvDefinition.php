<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One environment key a service needs, and where its value comes from.
 */
class ServiceEnvDefinition extends Model
{
    use HasFactory;

    public const SOURCE_LITERAL = 'literal';

    public const SOURCE_SECRET = 'secret';

    public const SOURCE_GENERATED = 'generated';

    public const SOURCE_SERVICE_URL = 'service_url';

    public const SOURCE_STACK = 'stack';

    protected $fillable = [
        'service_id',
        'key',
        'source',
        'required',
        'is_build_arg',
        'default_value',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'is_build_arg' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Values the control plane works out rather than asking anyone to type. */
    public function isDerived(): bool
    {
        return in_array($this->source, [
            self::SOURCE_GENERATED,
            self::SOURCE_SERVICE_URL,
            self::SOURCE_STACK,
        ], true);
    }

    public function isSecret(): bool
    {
        return $this->source === self::SOURCE_SECRET
            || $this->source === self::SOURCE_GENERATED;
    }
}
