<?php

namespace App\Http\Controllers;

use App\Jobs\BuildJob;
use App\Jobs\ReleaseJob;
use App\Models\Build;
use App\Models\Release;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Build\ImageBuilder;
use App\Services\Release\ReleasePlanner;
use App\Services\Release\VersionsBoard;
use App\Services\Stack\EnvResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The screens behind the board's buttons:
 *
 *  - Update <client> testing: the newest build of each service for that
 *    client's editions, each one holdable or settable to an older build.
 *  - Promote <client> to live: exactly what testing runs, per service.
 *  - Emergency: chosen builds straight to live, with a typed reason.
 *
 * Run starts at once, unless a destructive migration needs a window, in
 * which case it starts itself at the agreed time.
 */
class StackPlanController extends Controller
{
    public function __construct(
        private readonly ReleasePlanner $planner,
        private readonly VersionsBoard $board,
        private readonly EnvResolver $env,
        private readonly ImageBuilder $builder,
    ) {}

    public function show(Request $request, Stack $stack): Response
    {
        $stack->load('client', 'server');
        $emergency = $stack->isLive() && $request->boolean('emergency');
        $mode = $stack->isLive() && ! $emergency ? 'promote' : ($emergency ? 'emergency' : 'update');

        return Inertia::render('stacks/plan', [
            'stack' => [
                'id' => $stack->id,
                'tier' => $stack->tier,
                'label' => $stack->label(),
                'client' => ['name' => $stack->client->name, 'slug' => $stack->client->slug],
                'server_ready' => (bool) $stack->server?->isProvisioned(),
                'pinned' => (bool) $stack->pinned,
            ],
            'mode' => $mode,
            'rows' => $mode === 'promote' ? $this->promotionRows($stack) : $this->updateRows($stack),
        ]);
    }

    public function store(Request $request, Stack $stack): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['update', 'promote', 'emergency'])],
            'selection' => ['array'],
            'selection.*' => ['integer'],
            'service_ids' => ['array'],
            'service_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
        ]);

        $stack->load('client');

        try {
            $release = match ($data['mode']) {
                'promote' => $this->planner->promote(
                    $this->testingOf($stack),
                    $request->user(),
                    array_map('intval', $data['service_ids'] ?? []),
                ),
                'emergency' => $stack->isLive()
                    ? $this->planner->chosen($stack, $data['selection'] ?? [], Release::KIND_EMERGENCY, $request->user(), $data['reason'] ?? null)
                    : throw new RuntimeException('An emergency release goes to live.'),
                default => $stack->isLive()
                    ? throw new RuntimeException('Live is updated by promoting what testing runs.')
                    : $this->planner->chosen($stack, $data['selection'] ?? [], Release::KIND_NORMAL, $request->user()),
            };
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($release->items->isEmpty()) {
            $release->delete();

            return back()->with('error', 'Nothing to deploy: every selected service already runs that build.');
        }

        if ($release->needsWindow()) {
            if (empty($data['scheduled_for'])) {
                $release->delete();

                return back()->withErrors(['scheduled_for' => 'This drops or rewrites data. Agree a time with the client and enter it.']);
            }

            $release->update(['status' => 'scheduled', 'scheduled_for' => $data['scheduled_for']]);

            return redirect()->route('releases.show', $release)
                ->with('success', 'Scheduled. It starts on its own at the agreed time, with a backup of the affected tables first.');
        }

        ReleaseJob::launch($release, $request->user());

        return redirect()->route('releases.show', $release);
    }

    /**
     * Promote's missing piece for a per-stack service: build the commit
     * testing runs, with live's values.
     */
    public function buildForLive(Request $request, Stack $stack): RedirectResponse
    {
        $testing = $this->testingOf($stack);
        $live = $testing->client->stacks()->where('tier', Stack::TIER_LIVE)->firstOrFail();
        $queued = 0;

        foreach ($this->planner->promotionRows($testing) as $row) {
            if ($row['status'] !== 'needs_build' || $row['testing_build'] === null) {
                continue;
            }

            $build = $this->builder->create(
                $row['service'],
                $row['testing_build']->branch,
                $row['testing_build']->commit_sha,
                $live,
                Build::TRIGGER_MANUAL,
                $request->user(),
            );
            BuildJob::dispatch($build->id);
            $queued++;
        }

        return back()->with('success', $queued
            ? "{$queued} build(s) queued for live. Promote once they finish."
            : 'Nothing needs building.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function updateRows(Stack $stack): array
    {
        return StackService::query()
            ->with('service', 'stack.client', 'stack.server')
            ->where('stack_id', $stack->id)
            ->where('enabled', true)
            ->get()
            ->sortBy(fn (StackService $s) => [$s->service->identity_provider ? 0 : 1, $s->service->position, $s->service->slug])
            ->map(function (StackService $entitlement) {
                $current = $this->planner->buildByDigest($entitlement->current_digest);
                $candidates = $this->planner->candidates($entitlement);

                return [
                    'service' => ['id' => $entitlement->service->id, 'name' => $entitlement->service->name],
                    'edition' => $entitlement->edition,
                    'current' => $current ? $this->board->buildSummary($current) : null,
                    'deployed' => filled($entitlement->current_digest),
                    'candidates' => $candidates->map(fn (Build $b) => $this->board->buildSummary($b))->values(),
                    'missing_env' => $this->env->missingRequired($entitlement),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function promotionRows(Stack $live): array
    {
        return array_map(fn (array $row) => [
            'service' => ['id' => $row['service']->id, 'name' => $row['service']->name],
            'testing' => $row['testing_build'] ? $this->board->buildSummary($row['testing_build']) : null,
            'live' => $row['live_build'] ? $this->board->buildSummary($row['live_build']) : null,
            'target' => $row['target_build'] ? $this->board->buildSummary($row['target_build']) : null,
            'status' => $row['status'],
        ], $this->planner->promotionRows($this->testingOf($live)));
    }

    private function testingOf(Stack $stack): Stack
    {
        return $stack->isLive()
            ? $stack->client->stacks()->where('tier', Stack::TIER_TESTING)->firstOrFail()
            : $stack;
    }
}
