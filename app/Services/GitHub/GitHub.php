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

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com/')
            ->withToken((string) Setting::get(Setting::GITHUB_TOKEN))
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20);
    }
}
