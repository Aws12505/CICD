<?php

namespace App\Http\Controllers;

use App\Jobs\BuildJob;
use App\Models\Build;
use App\Models\Service;
use App\Services\Build\ImageBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every image the control plane has built, and "Build now".
 */
class BuildController extends Controller
{
    public function __construct(private readonly ImageBuilder $builder) {}

    public function index(Request $request): Response
    {
        $service = $request->string('service')->toString();

        return Inertia::render('builds/index', [
            'builds' => Build::query()
                ->with('service', 'user')
                ->when($service !== '', fn ($q) => $q->whereHas('service', fn ($q) => $q->where('slug', $service)))
                ->latest('id')
                ->limit(150)
                ->get()
                ->map(fn (Build $build) => self::summarise($build)),
            'services' => Service::query()->orderBy('position')->orderBy('name')->get()->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'default_branch' => $s->default_branch,
                'per_stack_build' => $s->per_stack_build,
            ]),
            'filter' => $service ?: null,
        ]);
    }

    /** Build now: the head of a branch, for one service. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'exists:services,id'],
            'branch' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9._\/-]+$/', 'not_regex:/\.\./'],
        ]);

        $service = Service::findOrFail($data['service_id']);
        $branch = $data['branch'];

        if ($branch !== $service->default_branch && Build::editionFor($branch) === null) {
            return back()->withErrors(['branch' => "Build {$service->default_branch} or an edition/… branch. Other branches are never deployed."]);
        }

        $stacks = BuildWebhookController::buildsFor($service, $branch);

        if ($stacks === []) {
            return back()->with('error', "No client runs {$service->name} on that edition, so there is nothing to build it for.");
        }

        $builds = [];

        foreach ($stacks as $stack) {
            $build = $this->builder->create($service, $branch, null, $stack, Build::TRIGGER_MANUAL, $request->user());
            BuildJob::dispatch($build->id);
            $builds[] = $build;
        }

        return count($builds) === 1
            ? redirect()->route('tasks.show', $builds[0]->task_id)
            : redirect()->route('builds.index', ['service' => $service->slug])->with('success', count($builds).' builds queued, one per client.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function summarise(Build $build): array
    {
        return [
            'id' => $build->id,
            'label' => $build->label(),
            'service' => $build->service->name,
            'service_slug' => $build->service->slug,
            'number' => $build->number,
            'edition' => $build->edition,
            'stack_id' => $build->stack_id,
            'branch' => $build->branch,
            'sha' => $build->commit_sha ? $build->shortSha() : null,
            'status' => $build->status,
            'trigger' => $build->trigger,
            'user' => $build->user?->name,
            'migration_kind' => $build->migration_kind,
            'commits' => count($build->changelog ?? []),
            'changelog' => $build->changelog ?? [],
            'error' => $build->error,
            'task_id' => $build->task_id,
            'built_at' => $build->built_at?->toIso8601String(),
            'created_at' => $build->created_at?->toIso8601String(),
        ];
    }
}
