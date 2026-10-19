<?php

namespace App\Services\Monitoring;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\Remote\CommandRunner;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Agentless metrics (decision 22): one SSH call samples everything, nothing
 * is installed on the server.
 *
 * CPU is measured over one second from /proc/stat; memory, swap, load, disk
 * and network come from /proc and df; containers from docker stats and ps;
 * certificate expiry from /etc/letsencrypt.
 */
class MetricsCollector
{
    /**
     * Each line starts with a tag the parser switches on. Everything that can
     * be absent (docker, certificates) is allowed to fail without failing the
     * sample.
     */
    public const SCRIPT = <<<'SH'
    read -r _ a b c d e f g h _ < /proc/stat; t1=$((a+b+c+d+e+f+g+h)); i1=$((d+e))
    sleep 1
    read -r _ a b c d e f g h _ < /proc/stat; t2=$((a+b+c+d+e+f+g+h)); i2=$((d+e))
    echo "cpu $t1 $i1 $t2 $i2"
    echo "load $(cut -d' ' -f1-3 /proc/loadavg)"
    awk '/^(MemTotal|MemAvailable|SwapTotal|SwapFree):/ {print "mem", $1, $2}' /proc/meminfo
    df -B1 --output=size,used / | tail -n 1 | awk '{print "disk", $1, $2}'
    iface=$(ip route show default 2>/dev/null | awk '{print $5; exit}')
    if [ -n "$iface" ]; then sed 's/:/ /' /proc/net/dev | awk -v i="$iface" '$1 == i {print "net", $2, $10}'; fi
    echo "uptime $(cut -d' ' -f1 /proc/uptime)"
    docker stats --no-stream --format 'stat|{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}|{{.MemPerc}}' 2>/dev/null || true
    docker ps -a --format 'ps|{{.Names}}|{{.State}}|{{.Status}}|{{.Image}}' 2>/dev/null || true
    for d in /etc/letsencrypt/live/*/; do
      [ -f "$d/cert.pem" ] || continue
      echo "cert|$(basename "$d")|$(openssl x509 -enddate -noout -in "$d/cert.pem" 2>/dev/null | cut -d= -f2)"
    done
    true
    SH;

    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * Sample, store, and record reachability. Returns null when the server
     * did not answer.
     */
    public function collect(Server $server): ?ServerMetric
    {
        $target = $server->target();

        try {
            $result = $this->runner->run($target, self::SCRIPT, timeout: 45);
        } finally {
            $target->cleanup();
        }

        if ($result->failed()) {
            $server->markUnreachable($result->failureReason());

            return null;
        }

        $parsed = self::parse($result->stdout);

        $server->forceFill(['certificates' => $parsed['certificates']])->save();
        $server->markOnline();

        unset($parsed['certificates']);

        return ServerMetric::create($parsed + ['server_id' => $server->id, 'recorded_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function parse(string $output): array
    {
        $sample = [
            'cpu_percent' => 0.0, 'load1' => 0.0, 'load5' => 0.0, 'load15' => 0.0,
            'mem_total' => 0, 'mem_used' => 0, 'swap_total' => 0, 'swap_used' => 0,
            'disk_total' => 0, 'disk_used' => 0, 'net_rx' => 0, 'net_tx' => 0,
            'uptime_seconds' => 0, 'containers' => [], 'certificates' => [],
        ];
        $mem = [];
        $stats = [];
        $ps = [];

        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'stat|') || str_starts_with($line, 'ps|') || str_starts_with($line, 'cert|')) {
                $parts = explode('|', $line);

                match ($parts[0]) {
                    'stat' => $stats[$parts[1] ?? ''] = $parts,
                    'ps' => $ps[$parts[1] ?? ''] = $parts,
                    'cert' => self::certificate($sample, $parts),
                };

                continue;
            }

            $f = preg_split('/\s+/', $line);

            switch ($f[0] ?? '') {
                case 'cpu':
                    [$t1, $i1, $t2, $i2] = array_map('intval', array_slice($f, 1, 4) + [0, 0, 0, 0]);
                    $total = $t2 - $t1;
                    $sample['cpu_percent'] = $total > 0 ? round(100 * (1 - ($i2 - $i1) / $total), 1) : 0.0;
                    break;
                case 'load':
                    [$sample['load1'], $sample['load5'], $sample['load15']] = array_map('floatval', array_slice($f, 1, 3) + [0, 0, 0]);
                    break;
                case 'mem':
                    $mem[rtrim($f[1] ?? '', ':')] = (int) ($f[2] ?? 0) * 1024;
                    break;
                case 'disk':
                    $sample['disk_total'] = (int) ($f[1] ?? 0);
                    $sample['disk_used'] = (int) ($f[2] ?? 0);
                    break;
                case 'net':
                    $sample['net_rx'] = (int) ($f[1] ?? 0);
                    $sample['net_tx'] = (int) ($f[2] ?? 0);
                    break;
                case 'uptime':
                    $sample['uptime_seconds'] = (int) ($f[1] ?? 0);
                    break;
            }
        }

        $sample['mem_total'] = $mem['MemTotal'] ?? 0;
        $sample['mem_used'] = max(0, ($mem['MemTotal'] ?? 0) - ($mem['MemAvailable'] ?? 0));
        $sample['swap_total'] = $mem['SwapTotal'] ?? 0;
        $sample['swap_used'] = max(0, ($mem['SwapTotal'] ?? 0) - ($mem['SwapFree'] ?? 0));

        foreach ($ps as $name => $p) {
            $s = $stats[$name] ?? null;

            $sample['containers'][] = [
                'name' => $name,
                'state' => $p[2] ?? '',
                'status' => $p[3] ?? '',
                'image' => $p[4] ?? '',
                'running' => ($p[2] ?? '') === 'running',
                'cpu' => $s ? (float) rtrim($s[2] ?? '0', '%') : 0.0,
                'mem_bytes' => $s ? self::bytes(trim(explode('/', $s[3] ?? '')[0])) : 0,
                'mem_percent' => $s ? (float) rtrim($s[4] ?? '0', '%') : 0.0,
            ];
        }

        usort($sample['containers'], fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $sample;
    }

    /** "512.3MiB", "1.2GiB", "800kB" → bytes. */
    public static function bytes(string $value): int
    {
        if (! preg_match('/^([\d.]+)\s*([kKMGT]?i?B)?$/', trim($value), $m)) {
            return 0;
        }

        $units = [
            'B' => 1, 'kB' => 1000, 'KB' => 1000, 'KiB' => 1024, 'MB' => 1000 ** 2, 'MiB' => 1024 ** 2,
            'GB' => 1000 ** 3, 'GiB' => 1024 ** 3, 'TB' => 1000 ** 4, 'TiB' => 1024 ** 4,
        ];

        return (int) round((float) $m[1] * ($units[$m[2] ?? 'B'] ?? 1));
    }

    private static function certificate(array &$sample, array $parts): void
    {
        try {
            $expires = CarbonImmutable::parse($parts[2] ?? '')->toIso8601String();
        } catch (Throwable) {
            return;
        }

        $sample['certificates'][] = ['domain' => $parts[1] ?? '', 'expires_at' => $expires];
    }
}
