<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sample of a server's health: CPU, memory, disk, load, network and
 * every container. Taken once a minute, kept seven days.
 */
class ServerMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id', 'recorded_at', 'cpu_percent', 'load1', 'load5', 'load15',
        'mem_total', 'mem_used', 'swap_total', 'swap_used', 'disk_total',
        'disk_used', 'net_rx', 'net_tx', 'uptime_seconds', 'containers',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'containers' => 'array',
            'cpu_percent' => 'float',
            'load1' => 'float',
            'load5' => 'float',
            'load15' => 'float',
            'mem_total' => 'integer',
            'mem_used' => 'integer',
            'swap_total' => 'integer',
            'swap_used' => 'integer',
            'disk_total' => 'integer',
            'disk_used' => 'integer',
            'net_rx' => 'integer',
            'net_tx' => 'integer',
            'uptime_seconds' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function memPercent(): float
    {
        return $this->mem_total > 0 ? round(100 * $this->mem_used / $this->mem_total, 1) : 0.0;
    }

    public function diskPercent(): float
    {
        return $this->disk_total > 0 ? round(100 * $this->disk_used / $this->disk_total, 1) : 0.0;
    }
}
