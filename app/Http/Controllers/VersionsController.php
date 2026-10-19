<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseJob;
use App\Models\Client;
use App\Models\Stack;
use App\Services\Release\ReleasePlanner;
use App\Services\Release\VersionsBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The Versions board, and the actions that take several clients at once.
 *
 * Still a click, never automatic (decision 18). Pinned clients are skipped,
 * and so is any client whose update would need a maintenance window: that
 * has to be agreed with them, from their own plan screen.
 */
class VersionsController extends Controller
{
    public function __construct(
        private readonly VersionsBoard $board,
        private readonly ReleasePlanner $planner,
    ) {}

    public function index(): Response
    {
        return Inertia::render('versions/index', $this->board->data());
    }

    public function updateTesting(Request $request): RedirectResponse
    {
        return $this->forEachClient($request, Stack::TIER_TESTING, fn (Stack $testing) => $this->planner->normal($testing, $request->user()));
    }

    public function promote(Request $request): RedirectResponse
    {
        return $this->forEachClient($request, Stack::TIER_TESTING, fn (Stack $testing) => $this->planner->promote($testing, $request->user()));
    }

    /** Pin: leave this client out of every multi-client update. */
    public function pin(Client $client): RedirectResponse
    {
        $pinned = ! (bool) $client->stacks()->where('tier', Stack::TIER_LIVE)->value('pinned');

        $client->stacks()->update(['pinned' => $pinned]);

        return back()->with('success', $pinned
            ? "{$client->name} is pinned: multi-client updates will skip it."
            : "{$client->name} is unpinned.");
    }

    private function forEachClient(Request $request, string $tier, \Closure $plan): RedirectResponse
    {
        $data = $request->validate([
            'client_ids' => ['required', 'array', 'min:1'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
        ]);

        $started = [];
        $skipped = [];

        foreach (Client::with('stacks.server')->whereIn('id', $data['client_ids'])->orderBy('name')->get() as $client) {
            $stack = $client->stacks->firstWhere('tier', $tier);

            if ($stack === null || $stack->pinned) {
                $skipped[] = "{$client->name} (pinned)";

                continue;
            }

            try {
                $release = $plan($stack);
            } catch (RuntimeException $e) {
                $skipped[] = "{$client->name} ({$e->getMessage()})";

                continue;
            }

            if ($release->items->isEmpty()) {
                $release->delete();
                $skipped[] = "{$client->name} (already up to date)";

                continue;
            }

            if ($release->needsWindow()) {
                $release->delete();
                $skipped[] = "{$client->name} (needs a maintenance window — plan it from the client)";

                continue;
            }

            ReleaseJob::launch($release, $request->user());
            $started[] = $client->name;
        }

        $message = ($started ? 'Started: '.implode(', ', $started).'.' : 'Nothing started.')
            .($skipped ? ' Skipped: '.implode('; ', $skipped).'.' : '');

        return redirect()->route('releases.index')->with($started ? 'success' : 'error', $message);
    }
}
