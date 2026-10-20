<?php

namespace App\Services\GitHub;

use App\Models\Service;
use App\Models\Setting;
use App\Services\Remote\CommandRunner;
use App\Support\Shell;
use App\Targets\LocalTarget;
use Throwable;

/**
 * Can the control plane read one service's repository the way a build does?
 *
 * Asks twice, because they answer different questions. GitHub's API says
 * *why* not, in words of what to change on GitHub. `git ls-remote`, run with
 * exactly the authentication the build uses, says whether the build will
 * actually work. If the two disagree, the git result wins and its own error is
 * shown, so nothing here can pass while the build still fails.
 */
class AccessCheck
{
    public function __construct(
        private readonly GitHub $github,
        private readonly CommandRunner $runner,
    ) {}

    /**
     * @return array{service: string, repository: string, branch: string, ok: bool, message: string}
     */
    public function service(Service $service): array
    {
        $identity = [
            'service' => $service->name,
            'repository' => $service->repo_full_name,
            'branch' => $service->default_branch,
        ];

        $git = $this->probe($service);

        if ($git['ok']) {
            return $identity + ['ok' => true, 'message' => "Can read {$service->repo_full_name}@{$service->default_branch} with git."];
        }

        // Git failed: ask the API for the reason in plain words.
        try {
            $api = $this->github->checkRead($service->repo_full_name, $service->default_branch);
        } catch (Throwable $e) {
            $api = ['ok' => false, 'problem' => 'error', 'message' => 'Could not reach GitHub\'s API: '.$e->getMessage()];
        }

        $message = $api['ok']
            ? "GitHub's API can read it, but git could not fetch it: {$git['detail']}. That points at the connection from this server to github.com rather than at the token."
            : $api['message']." (git said: {$git['detail']})";

        return $identity + ['ok' => false, 'message' => $message];
    }

    /**
     * `git ls-remote` for the build branch, authenticated like a build.
     * Exit 2 with --exit-code means the repository was read but the branch
     * is not there.
     *
     * @return array{ok: bool, detail: string}
     */
    public function probe(Service $service): array
    {
        $result = $this->runner->run(
            new LocalTarget,
            'git ls-remote --exit-code '
                .Shell::quote('https://github.com/'.$service->repo_full_name.'.git')
                .' '.Shell::quote('refs/heads/'.$service->default_branch),
            env: GitHub::gitEnv(),
            timeout: 60,
        );

        if ($result->successful()) {
            return ['ok' => true, 'detail' => 'readable'];
        }

        if ($result->exitCode === 2) {
            return ['ok' => false, 'detail' => "the repository is readable but the branch {$service->default_branch} does not exist"];
        }

        return ['ok' => false, 'detail' => $this->firstLine($result->stderr.$result->stdout)];
    }

    private function firstLine(string $output): string
    {
        $line = collect(preg_split('/\r?\n/', trim($output)))->first(fn ($l) => str_contains($l, 'fatal') || str_contains($l, 'error')) ?? trim(strtok($output, "\n") ?: 'no output');

        // The token is sent as a header, never printed, but never risk it.
        $token = Setting::get(Setting::GITHUB_TOKEN);

        return $token ? str_replace($token, '******', (string) $line) : (string) $line;
    }
}
