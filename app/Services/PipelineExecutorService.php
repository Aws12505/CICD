<?php

namespace App\Services;

use App\Events\PipelineLogLine;
use App\Events\PipelineRunStatusChanged;
use App\Models\PipelineRun;
use App\Models\PipelineStepResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class PipelineExecutorService
{
    public function execute(PipelineRun $run): void
    {
        $pipeline = $run->pipeline;
        $steps = $pipeline->steps ?? [];

        $logPath = storage_path($run->log_path);
        File::ensureDirectoryExists(dirname($logPath));
        File::put($logPath, '');

        $run->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->broadcastStatus($run);

        $context = [
            'repo' => $pipeline->project->repo_full_name ?? $pipeline->project->repo_name,
            'branch' => $pipeline->branch,
            'commit' => $run->commit_sha ?? '',
        ];

        $this->writeLog($run, $logPath, "Run #{$run->id}\n");
        $this->writeLog($run, $logPath, "Project: {$context['repo']}\n");
        $this->writeLog($run, $logPath, "Branch: {$context['branch']}\n");
        $this->writeLog($run, $logPath, "Commit: {$context['commit']}\n");
        $this->writeLog($run, $logPath, "Started: {$run->started_at}\n\n");

        $stepRecords = $this->bootstrapStepRecords($run, $steps);

        $startedAt = microtime(true);
        $failed = false;

        foreach ($steps as $index => $step) {
            $record = $stepRecords[$index];

            $record->update([
                'status' => 'running',
                'started_at' => now(),
            ]);
            $this->broadcastStatus($run);

            try {
                $exitCode = $this->runStep($run, $logPath, $step, $context);
            } catch (Throwable $e) {
                $this->writeLog($run, $logPath, "\nFATAL: " . $e->getMessage() . "\n");
                Log::error('Pipeline step crashed', ['run_id' => $run->id, 'exception' => $e]);
                $exitCode = 255;
            }

            $stepDuration = (int) ((microtime(true) - $startedAt) * 1000);

            $record->update([
                'status' => $exitCode === 0 ? 'success' : 'failed',
                'exit_code' => $exitCode,
                'finished_at' => now(),
                'duration_ms' => $stepDuration,
            ]);

            $this->broadcastStatus($run);

            if ($exitCode !== 0) {
                $failed = true;
                $this->writeLog($run, $logPath, "\nStep failed with exit code {$exitCode}\n");

                foreach ($stepRecords as $j => $remaining) {
                    if ($j > $index) {
                        $remaining->update(['status' => 'skipped']);
                    }
                }

                break;
            }
        }

        $totalDuration = (int) ((microtime(true) - $startedAt) * 1000);

        $run->update([
            'status' => $failed ? 'failed' : 'success',
            'finished_at' => now(),
            'duration_ms' => $totalDuration,
        ]);

        $this->writeLog($run, $logPath, "\nPipeline " . ($failed ? 'FAILED' : 'SUCCEEDED') . " in {$totalDuration}ms\n");
        $this->broadcastStatus($run);
    }

    /**
     * @return PipelineStepResult[]
     */
    private function bootstrapStepRecords(PipelineRun $run, array $steps): array
    {
        $records = [];
        foreach ($steps as $index => $step) {
            $records[$index] = $run->stepResults()->create([
                'step_index' => $index,
                'name' => $step['name'] ?? "Step {$index}",
                'command' => $step['command'] ?? '',
                'status' => 'pending',
            ]);
        }

        return $records;
    }

    private function runStep(PipelineRun $run, string $logPath, array $step, array $context): int
    {
        $command = $this->interpolate((string) ($step['command'] ?? ''), $context);
        $cwd = $this->interpolate((string) ($step['cwd'] ?? base_path()), $context);
        $env = $step['env'] ?? [];
        $timeout = (int) ($step['timeout'] ?? 0);

        $envMerged = array_merge(getenv() ?: [], $env, [
            'CICD_REPO' => $context['repo'],
            'CICD_BRANCH' => $context['branch'],
            'CICD_COMMIT' => $context['commit'],
        ]);

        $this->writeLog($run, $logPath, "\n$ cd {$cwd}\n$ {$command}\n\n");

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            ['/bin/bash', '-lc', $command],
            $descriptorSpec,
            $pipes,
            is_dir($cwd) ? $cwd : null,
            $envMerged
        );

        if (!is_resource($process)) {
            $this->writeLog($run, $logPath, "Failed to spawn process.\n");

            return 255;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startedAt = microtime(true);

        while (true) {
            $status = proc_get_status($process);

            $this->drainStream($pipes[1], $run, $logPath);
            $this->drainStream($pipes[2], $run, $logPath);

            if (!$status['running']) {
                break;
            }

            if ($timeout > 0 && (microtime(true) - $startedAt) > $timeout) {
                $this->writeLog($run, $logPath, "\nTimeout after {$timeout}s — terminating.\n");
                proc_terminate($process, 9);
                break;
            }

            usleep(50_000);
        }

        $this->drainStream($pipes[1], $run, $logPath);
        $this->drainStream($pipes[2], $run, $logPath);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }

    private function drainStream($pipe, PipelineRun $run, string $logPath): void
    {
        while (($line = fgets($pipe)) !== false) {
            $this->writeLog($run, $logPath, $line);
        }
    }

    private function writeLog(PipelineRun $run, string $logPath, string $line): void
    {
        File::append($logPath, $line);

        try {
            broadcast(new PipelineLogLine($run->id, $line));
        } catch (Throwable $e) {
            Log::warning('Failed to broadcast log line', ['exception' => $e->getMessage()]);
        }
    }

    private function broadcastStatus(PipelineRun $run): void
    {
        try {
            broadcast(new PipelineRunStatusChanged($run->fresh(['stepResults'])));
        } catch (Throwable $e) {
            Log::warning('Failed to broadcast status', ['exception' => $e->getMessage()]);
        }
    }

    private function interpolate(string $value, array $context): string
    {
        $replacements = [
            '${repo}' => $context['repo'] ?? '',
            '${branch}' => $context['branch'] ?? '',
            '${commit}' => $context['commit'] ?? '',
        ];

        return strtr($value, $replacements);
    }
}
