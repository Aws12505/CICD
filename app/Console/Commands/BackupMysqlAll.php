<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupMysqlAll extends Command
{
    protected $signature = 'backup:mysql {--keep= : Number of backups to retain (defaults to config(backup.mysql.keep))}';

    protected $description = 'Dump every database on the MySQL server (not just DB_DATABASE) to a compressed file';

    private const DIRECTORY = 'backups/mysql';

    public function handle(): int
    {
        $host = config('backup.mysql.host');
        $port = (string) config('backup.mysql.port');
        $username = config('backup.mysql.username');
        $password = config('backup.mysql.password');

        if (! $username || $password === null || $password === '') {
            $this->error('BACKUP_DB_USERNAME / BACKUP_DB_PASSWORD are not configured. See config/backup.php.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);

        $filename = 'mysql-all-'.now()->format('Y-m-d_His').'.sql.gz';
        $destination = $disk->path(self::DIRECTORY.'/'.$filename);
        $partial = $destination.'.part';

        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --all-databases --single-transaction --quick --routines --triggers --events --hex-blob | gzip -9 > %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($partial)
        );

        $process = Process::fromShellCommandline($command, null, ['MYSQL_PWD' => $password]);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($partial) || filesize($partial) === 0) {
            if (is_file($partial)) {
                unlink($partial);
            }

            $message = trim($process->getErrorOutput()) ?: 'mysqldump produced no output.';
            $this->error("MySQL backup failed: {$message}");
            Log::error('MySQL full-instance backup failed', ['error' => $message]);

            return self::FAILURE;
        }

        rename($partial, $destination);

        $sizeMb = number_format(filesize($destination) / 1024 / 1024, 2);
        $this->info("MySQL backup written to {$destination} ({$sizeMb} MB)");
        Log::info('MySQL full-instance backup completed', ['path' => $destination, 'size_mb' => $sizeMb]);

        $this->pruneOldBackups($disk, (int) ($this->option('keep') ?? config('backup.mysql.keep')));

        return self::SUCCESS;
    }

    private function pruneOldBackups(\Illuminate\Contracts\Filesystem\Filesystem $disk, int $keep): void
    {
        $keep = max($keep, 1);

        $files = collect($disk->files(self::DIRECTORY))
            ->filter(fn (string $file) => str_ends_with($file, '.sql.gz'))
            ->sortByDesc(fn (string $file) => $disk->lastModified($file))
            ->values();

        $files->slice($keep)->each(function (string $file) use ($disk) {
            $disk->delete($file);
            $this->line("Pruned old backup: {$file}");
            Log::info('Pruned old MySQL backup', ['path' => $file]);
        });
    }
}
