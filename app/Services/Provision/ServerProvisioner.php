<?php

namespace App\Services\Provision;

use App\Models\Server;
use App\Models\Setting;
use App\Models\Task;
use App\Services\Guide\SetupGuide;
use App\Services\Remote\CommandRunner;
use App\Services\Remote\RemoteFiles;
use App\Services\Remote\ServerProbe;
use Throwable;

/**
 * "Set up server": takes a bare VPS to one that can host stacks.
 *
 * Installs Docker, nginx and certbot, locks the firewall to the control plane,
 * starts the shared MySQL, Redis and NATS, and gives every stack on the server
 * its own users and streams. Safe to run again at any time.
 */
class ServerProvisioner
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly InfraRenderer $infra,
        private readonly StackProvisioner $stacks,
        private readonly ServerProbe $probe,
    ) {}

    public function provision(Server $server, Task $task): bool
    {
        $task->start();

        // Mint everything first so it can all be masked before anything runs.
        foreach (['mysql_root', 'redis_root', 'nats_token'] as $key) {
            $server->secret($key);
        }

        $task->mask(...$server->fresh()->allSecrets());
        $task->mask(Setting::get(Setting::REGISTRY_PULL_TOKEN));

        foreach ($server->stacks as $stack) {
            $stack->secret('mysql_password');
            $stack->secret('redis_password');
            $task->mask(...$stack->fresh()->allSecrets());
        }

        $target = $server->target();

        try {
            $task->step('Preparing the server');

            $result = $this->runner->run(
                $target,
                RemoteFiles::runScript((string) file_get_contents(resource_path('provision/bootstrap.sh'))),
                env: [
                    'REGISTRY_HOST' => $this->registryHost(),
                    'REGISTRY_USER' => (string) Setting::get(Setting::REGISTRY_USERNAME, ''),
                    'REGISTRY_TOKEN' => (string) Setting::get(Setting::REGISTRY_PULL_TOKEN, ''),
                    'SSH_PORT' => (string) $server->ssh_port,
                    'ALLOW_SSH_FROM' => trim(preg_replace('/[^0-9a-fA-F.:\/ ]/', ' ', (string) Setting::get(Setting::CONTROL_PLANE_IP, ''))),
                ],
                timeout: 1800,
                onOutput: $task->logger(),
            );

            if ($result->failed()) {
                $task->fail('Preparing the server failed: '.$result->failureReason());

                return false;
            }

            $task->step('Starting the shared MySQL, Redis and NATS');

            foreach ($this->infra->files($server) as $path => [$contents, $mode, $writeOnce]) {
                $command = $writeOnce
                    ? RemoteFiles::writeIfMissing($path, $contents, $mode)
                    : RemoteFiles::write($path, $contents, $mode);

                if ($this->runner->run($target, $command, timeout: 60)->failed()) {
                    $task->fail("Could not write {$path}.");

                    return false;
                }
            }

            $result = $this->runner->run(
                $target,
                'docker compose -f '.InfraRenderer::ROOT.'/docker-compose.yml up -d --remove-orphans',
                timeout: 900,
                onOutput: $task->logger(),
            );

            if ($result->failed()) {
                $task->fail('Starting the shared services failed: '.$result->failureReason());

                return false;
            }

            if (! $this->waitForMysql($target, $task)) {
                $task->fail('MySQL did not become healthy within three minutes.');

                return false;
            }

            foreach ($server->stacks()->with('client')->get() as $stack) {
                $task->step("Preparing {$stack->label()}");

                $failure = $this->stacks->prepare($stack, $target, $task->logger());

                if ($failure !== null) {
                    $task->fail($failure);

                    return false;
                }
            }

            $server->forceFill([
                'provisioned_at' => now(),
                'capabilities' => $this->probe->capabilities($server),
            ])->save();
            $server->markOnline();
            SetupGuide::forget();

            $task->succeed('The server is ready. Deploy a stack to it next.');

            return true;
        } catch (Throwable $e) {
            report($e);
            $task->fail('Unexpected error: '.$e->getMessage());

            return false;
        } finally {
            $target->cleanup();
        }
    }

    private function waitForMysql($target, Task $task): bool
    {
        for ($attempt = 1; $attempt <= 36; $attempt++) {
            $status = trim($this->runner->run(
                $target,
                "docker inspect -f '{{.State.Health.Status}}' mysql",
                timeout: 20,
            )->stdout);

            if ($status === 'healthy') {
                $task->log("MySQL is healthy.\n");

                return true;
            }

            $task->log("Waiting for MySQL ({$status})…\n");
            $this->runner->run($target, 'sleep 5', timeout: 10);
        }

        return false;
    }

    private function registryHost(): string
    {
        return explode('/', (string) config('deploy.registry'), 2)[0];
    }
}
