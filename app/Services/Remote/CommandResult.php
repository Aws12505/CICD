<?php

namespace App\Services\Remote;

/**
 * The outcome of one command, wherever it ran.
 */
final class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $durationMs,
        public readonly bool $timedOut = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    /** stdout when there is any, otherwise stderr — for one-line probes. */
    public function output(): string
    {
        return trim($this->stdout) !== '' ? trim($this->stdout) : trim($this->stderr);
    }

    /** A short reason suitable for storing on a record or showing in the UI. */
    public function failureReason(): ?string
    {
        if ($this->successful()) {
            return null;
        }

        if ($this->timedOut) {
            return "Timed out after {$this->durationMs}ms";
        }

        $detail = trim($this->stderr) !== '' ? trim($this->stderr) : trim($this->stdout);

        return "Exit {$this->exitCode}".($detail !== '' ? ": {$detail}" : '');
    }
}
