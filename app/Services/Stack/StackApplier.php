<?php

namespace App\Services\Stack;

use App\Models\Stack;
use App\Models\StackService;
use App\Models\Task;
use App\Services\Remote\CommandRunner;
use App\Support\Shell;
use Throwable;

/**
 * "Apply": puts saved environment values into effect on one stack.
 *
 * Re-renders the stack's files and asks compose to bring the running services
 * up again; compose recreates only the containers whose configuration
 * changed. No build and no release.
 *
 * Only services already running what they were last released with are
 * touched. One that has never been deployed, or is mid-release, would be
 * started from whatever image its compose entry names — that is a release's
 * job, not this one's.
 */
class StackApplier
{
    public function __construct(
        private readonly StackSync $sync,
        private readonly StackRenderer $renderer,
        private readonly EnvResolver $env,
        private readonly CommandRunner $runner,
    ) {}

    public function apply(Stack $stack, Task $task): bool
    {
        $task->start();
        $stack->loadMissing('server', 'client');
        $task->mask(...$this->env->secretsFor($stack));

        $target = $stack->server->target();

        try {
            $task->step("Writing {$stack->label()}'s files");

            if ($failure = $this->sync->push($stack, $target)) {
                $task->fail('Could not write the files: '.$failure->failureReason());

                return false;
            }

            $running = StackService::query()
                ->with('service')
                ->where('stack_id', $stack->id)
                ->where('enabled', true)
                ->get()
                ->filter(fn (StackService $s) => filled($s->desired_digest) && $s->desired_digest === $s->current_digest);

            if ($running->isEmpty()) {
                $task->succeed('Saved. Nothing is running on this stack yet, so it takes effect on the first deploy.');

                return true;
            }

            $task->step('Restarting what changed');

            $services = $running->flatMap(fn (StackService $s) => $s->service->composeServices())->values()->all();
            $compose = 'docker compose -f '.Shell::quote($this->renderer->rootPath($stack).'/docker-compose.yml');

            $result = $this->runner->run(
                $target,
                $compose.' up -d --no-deps '.Shell::join($services),
                timeout: 600,
                onOutput: $task->logger(),
            );

            if ($result->failed()) {
                $task->fail('Restarting failed: '.$result->failureReason());

                return false;
            }

            $task->succeed('Applied. Containers whose settings changed were recreated.');

            return true;
        } catch (Throwable $e) {
            report($e);
            $task->fail('Unexpected error: '.$e->getMessage());

            return false;
        } finally {
            $target->cleanup();
        }
    }
}
