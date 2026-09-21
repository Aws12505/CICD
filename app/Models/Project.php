<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'repo_owner',
        'repo_name',
        'repo_full_name',
        'description',
        'github_secret',
    ];

    protected $hidden = [
        'github_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypted at rest. Note this makes the column unreadable if
            // APP_KEY ever changes — see docker-entrypoint-backend.sh, which
            // used to regenerate it on every boot.
            'github_secret' => 'encrypted',
        ];
    }

    public function pipelines(): HasMany
    {
        return $this->hasMany(Pipeline::class);
    }
}
