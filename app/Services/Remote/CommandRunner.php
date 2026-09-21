<?php

namespace App\Services\Remote;

use App\Contracts\Target;
use Closure;

/**
 * Runs one command against a Target and captures the result.
 *
 * The pipeline executor streams output line by line into a run log; this is
 * for everything else — connection checks, reading a container's image digest,
 * taking a dump — where the caller wants the output back rather than streamed.
 * Pass $onOutput to get both.
 */
class CommandRunner
{
    /**
     * @param  array<string, string|int>  $env
     * @param  int  $timeout  seconds; 0 disables
     * @param  Closure(string):void|null  $onOutput  called per chunk as it arrives
     */
    public function run(
        Target $target,
        string $command,
        ?string $cwd = null,
        array $env = [],
        int $timeout = 60,
        ?Closure $onOutput = null,
    ): CommandResult {
        $invocation = $target->invoke($command, $cwd, $env);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $startedAt = microtime(true);

        $process = @proc_open($invocation->argv, $descriptors, $pipes, $invocation->cwd, $invocation->env);

        if (! is_resource($process)) {
            return new CommandResult(
                exitCode: 255,
                stdout: '',
                stderr: 'Unable to start the process.',
                durationMs: 0,
            );
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);

            $this->drain($pipes[1], $stdout, $onOutput);
            $this->drain($pipes[2], $stderr, $onOutput);

            if (! $status['running']) {
                break;
            }

            if ($timeout > 0 && (microtime(true) - $startedAt) > $timeout) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            usleep(20_000);
        }

        $this->drain($pipes[1], $stdout, $onOutput);
        $this->drain($pipes[2], $stderr, $onOutput);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new CommandResult(
            exitCode: $timedOut ? 124 : $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            timedOut: $timedOut,
        );
    }

    /**
     * @param  resource  $pipe
     * @param  Closure(string):void|null  $onOutput
     */
    private function drain($pipe, string &$buffer, ?Closure $onOutput): void
    {
        while (($chunk = fgets($pipe)) !== false) {
            $buffer .= $chunk;

            if ($onOutput !== null) {
                $onOutput($chunk);
            }
        }
    }
}
