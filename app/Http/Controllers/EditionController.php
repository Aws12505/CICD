<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\GitHub\GitHub;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * Sync edition: merge the template into an edition branch (decision 13).
 *
 * One way only, testing → edition/*. A clean merge lands and the push
 * webhook builds it; a conflict opens a pull request against the edition for
 * someone to resolve. An edition is never merged back.
 */
class EditionController extends Controller
{
    public function __construct(private readonly GitHub $github) {}

    public function sync(Service $service, string $edition): RedirectResponse
    {
        abort_unless((bool) preg_match('/^[a-z0-9][a-z0-9-]*$/', $edition), 404);

        if (! $this->github->configured()) {
            return back()->with('error', 'Add a GitHub token in Settings first.');
        }

        $branch = 'edition/'.$edition;
        $template = $service->default_branch;

        try {
            $merge = $this->github->merge(
                $service->repo_full_name,
                $branch,
                $template,
                "Merge {$template} into {$branch}",
            );

            if ($merge->status() === 204) {
                return back()->with('success', "{$branch} already has everything in {$template}.");
            }

            if ($merge->successful()) {
                return back()->with('success', "Merged {$template} into {$branch}. Its build starts from the push.");
            }

            if ($merge->status() !== 409) {
                return back()->with('error', "GitHub refused the merge ({$merge->status()}): ".$merge->json('message'));
            }

            $pull = $this->github->openPullRequest(
                $service->repo_full_name,
                $branch,
                $template,
                "Sync {$branch} with {$template}",
                "Opened by the control plane: merging {$template} into {$branch} conflicts. Resolve it on this branch; merges flow one way only, so never merge {$branch} back into {$template}.",
            );

            return $pull->successful()
                ? back()->with('error', "{$template} conflicts with {$branch}. Pull request opened: ".$pull->json('html_url'))
                : back()->with('error', "{$template} conflicts with {$branch}, and a pull request could not be opened (".($pull->json('errors.0.message') ?? $pull->json('message')).'). One may already be open.');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not reach GitHub: '.$e->getMessage());
        }
    }
}
