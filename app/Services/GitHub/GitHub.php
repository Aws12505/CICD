<?php

namespace App\Services\GitHub;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The few GitHub calls the control plane makes, with the token from Settings.
 *
 * Every one of them is a record or a convenience rather than part of a
 * deploy: a failure here is logged and never stops a release.
 */
class GitHub
{
    /**
     * The environment that makes git authenticate to github.com with the saved
     * token: an HTTP header, so the token is never written into a URL or the
     * workspace's git config. Always forbids prompting: nobody can answer one.
     *
     * @return array<string, string>
     */
    public static function gitEnv(): array
    {
        $token = Setting::get(Setting::GITHUB_TOKEN);

        if (blank($token)) {
            return ['GIT_TERMINAL_PROMPT' => '0'];
        }

        return [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
            'GIT_CONFIG_VALUE_0' => 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$token),
        ];
    }

    public function configured(): bool
    {
        return Setting::has(Setting::GITHUB_TOKEN);
    }

    /**
     * Move a branch forward to a commit, only if that is a fast-forward.
     *
     * `main` is a high-water mark (decision 19): the newest commit live for
     * any client. GitHub refuses the update when it would not be a
     * fast-forward, which is exactly when `main` is already ahead.
     */
    public function fastForward(string $repository, string $branch, string $sha): Response
    {
        return $this->client()->patch("repos/{$repository}/git/refs/heads/{$branch}", [
            'sha' => $sha,
            'force' => false,
        ]);
    }

    /**
     * Merge one branch into another (testing → edition/*). Merges flow one
     * way only (decision 13).
     *
     * 201 merged, 204 already up to date, 409 conflict.
     */
    public function merge(string $repository, string $base, string $head, string $message): Response
    {
        return $this->client()->post("repos/{$repository}/merges", [
            'base' => $base,
            'head' => $head,
            'commit_message' => $message,
        ]);
    }

    public function openPullRequest(string $repository, string $base, string $head, string $title, string $body): Response
    {
        return $this->client()->post("repos/{$repository}/pulls", [
            'base' => $base,
            'head' => $head,
            'title' => $title,
            'body' => $body,
        ]);
    }

    /**
     * Whether the token can read a repository's code, and if not, why, in the
     * words of what to change on GitHub.
     *
     * Two calls, because they fail differently: the repository itself (does
     * the token see it at all?) and its build branch (needs Contents: read,
     * and proves the branch exists). A 404 from GitHub means "not found OR no
     * access", so the message names both.
     *
     * @return array{ok: bool, problem: ?string, message: string}
     */
    public function checkRead(string $repository, string $branch): array
    {
        $repo = $this->client()->get("repos/{$repository}");

        if ($repo->status() === 401) {
            return $this->verdict(false, 'bad-token', 'GitHub rejected the token: it is wrong, expired or revoked. Make a new one and paste it in Settings.');
        }

        if ($repo->status() === 403) {
            return $this->verdict(false, 'forbidden', $repo->header('X-GitHub-SSO')
                ? 'The organisation uses SAML single sign-on. On GitHub: Settings → Developer settings → your token → Configure SSO → Authorize for the organisation.'
                : 'GitHub refused access (403)'.($repo->json('message') ? ': '.$repo->json('message') : '').'. If the organisation restricts tokens, an owner has to approve it.');
        }

        if ($repo->status() === 404) {
            return $this->verdict(false, 'no-access', "The token cannot see {$repository}. Either the repository name is wrong, or the token was not granted it: for a fine-grained token the Resource owner must be the organisation (not your own account) and Repository access must include this repository. If the organisation requires approval, an owner approves it under Organisation settings → Personal access tokens.");
        }

        if (! $repo->successful()) {
            return $this->verdict(false, 'error', "GitHub answered {$repo->status()} for {$repository}.");
        }

        $branchResponse = $this->client()->get("repos/{$repository}/branches/".rawurlencode($branch));

        if ($branchResponse->status() === 404) {
            return $this->verdict(false, 'branch', "The token can see {$repository}, but not the branch {$branch}: either that branch does not exist (check the service's build branch) or the token lacks Contents → Read-only.");
        }

        return $branchResponse->successful()
            ? $this->verdict(true, null, "Can read {$repository}@{$branch}.")
            : $this->verdict(false, 'error', "GitHub answered {$branchResponse->status()} for branch {$branch}.");
    }

    /** @return array{ok: bool, problem: ?string, message: string} */
    private function verdict(bool $ok, ?string $problem, string $message): array
    {
        return ['ok' => $ok, 'problem' => $problem, 'message' => $message];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com/')
            ->withToken((string) Setting::get(Setting::GITHUB_TOKEN))
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20);
    }
}
