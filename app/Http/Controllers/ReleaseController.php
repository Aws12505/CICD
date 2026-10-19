<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseJob;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Models\Stack;
use App\Services\Release\ReleasePlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ReleaseController extends Controller
{
    public function __construct(
        private readonly ReleasePlanner $planner,
    ) {}

    public function index(): Response
    {
        return Inertia::render('releases/index', [
            'releases' => Release::query()
                ->with('stack.client', 'items.service')
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (Release $release) => $this->summarise($release)),
        ]);
    }

    public function show(Release $release): Response
    {
        $release->load('stack.client', 'items.service', 'items.build.service', 'creator', 'sourceRelease', 'task');

        return Inertia::render('releases/show', [
            'release' => $this->summarise($release) + [
                'reason' => $release->reason,
                'created_by' => $release->creator?->name,
                'scheduled_for' => $release->scheduled_for?->toIso8601String(),
                'window_started_at' => $release->window_started_at?->toIso8601String(),
                'window_ended_at' => $release->window_ended_at?->toIso8601String(),
                'affected_tables' => $release->affectedTables(),
                'source_release_id' => $release->source_release_id,
            ],
            'task' => $release->task ? TaskController::summarise($release->task) : null,
            'log' => $release->task?->contents() ?? '',
            'items' => $release->items->map(fn (ReleaseItem $item) => [
                'id' => $item->id,
                'service' => $item->service->name,
                'build' => $item->build?->label(),
                'slug' => $item->service->slug,
                'from_digest' => $item->from_digest,
                'to_digest' => $item->to_digest,
                'status' => $item->status,
                'error' => $item->error,
                'migration_kind' => $item->build?->migration_kind,
                'migration_reasons' => $item->build?->migration_reasons ?? [],
                'revertable' => $item->isRevertable(),
            ]),
        ]);
    }

    /** Plan a normal release for a stack. Nothing runs until it is started. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'stack_id' => ['required', 'exists:stacks,id'],
            'kind' => ['required', 'in:normal,promotion,emergency'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $stack = Stack::with('client.stacks')->findOrFail($data['stack_id']);

        try {
            $release = match ($data['kind']) {
                'promotion' => $this->planner->promote($stack, $request->user()),
                'emergency' => $this->planner->emergency($stack, (string) $data['reason'], $request->user()),
                default => $this->planner->normal($stack, $request->user()),
            };
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($release->items->isEmpty()) {
            $release->delete();

            return back()->with('error', 'Nothing to deploy — every service is already on its latest build.');
        }

        return redirect()->route('releases.show', $release)
            ->with('success', 'Release planned. Review it, then run it.');
    }

    /** Roll a stack back to what a past release recorded. */
    public function rollback(Request $request, Release $release): RedirectResponse
    {
        $rollback = $this->planner->rollback($release, $request->user());

        if ($rollback->items->isEmpty()) {
            $rollback->delete();

            return back()->with('error', 'That release recorded nothing to go back to.');
        }

        // One click: the digests are known-good by definition, and nothing is
        // migrated backwards. Restoring a dump stays a separate decision.
        ReleaseJob::launch($rollback, $request->user());

        return redirect()->route('releases.show', $rollback)
            ->with('success', 'Rolling back. Restoring a database dump is a separate decision.');
    }

    /** Agree a window for a destructive release. */
    public function schedule(Request $request, Release $release): RedirectResponse
    {
        $data = $request->validate([
            'scheduled_for' => ['required', 'date', 'after:now'],
        ]);

        $release->update(['scheduled_for' => $data['scheduled_for'], 'status' => 'scheduled']);

        return back()->with('success', 'Window agreed.');
    }

    /** Run a planned release now. Its log streams on the release page. */
    public function run(Request $request, Release $release): RedirectResponse
    {
        if ($release->task_id !== null || in_array($release->status, ['running', 'succeeded', 'failed'], true)) {
            return back()->with('error', 'That release has already been started.');
        }

        $release->load('items.build');

        if ($release->needsWindow() && $release->scheduled_for === null) {
            return back()->with('error', 'Agree a window with the client first.');
        }

        ReleaseJob::launch($release, $request->user());

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Release $release): array
    {
        return [
            'id' => $release->id,
            'kind' => $release->kind,
            'status' => $release->status,
            'client' => $release->stack->client->name,
            'tier' => $release->stack->tier,
            'stack_id' => $release->stack_id,
            'items_count' => $release->items->count(),
            'services' => $release->items->map(fn (ReleaseItem $i) => $i->service?->name)->filter()->values(),
            'scheduled_for' => $release->scheduled_for?->toIso8601String(),
            'needs_window' => $release->needsWindow(),
            'created_at' => $release->created_at?->toIso8601String(),
            'finished_at' => $release->finished_at?->toIso8601String(),
        ];
    }
}
