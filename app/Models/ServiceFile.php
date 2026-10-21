<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One build or run file a service overrides: the Dockerfile, the entrypoint,
 * the compose blocks, the nginx vhost (decision 27).
 *
 * `custom` carries the contents edited in the control plane; `repo` points
 * at a path in the service's repository and is only meaningful for files
 * that take part in the image build. A service with no row for a file uses
 * the control plane's default.
 */
class ServiceFile extends Model
{
    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_CUSTOM = 'custom';

    public const SOURCE_REPO = 'repo';

    protected $fillable = ['service_id', 'name', 'source', 'contents', 'repo_path'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
