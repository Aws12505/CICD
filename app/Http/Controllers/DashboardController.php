<?php

namespace App\Http\Controllers;

use App\Models\PipelineRun;
use App\Models\Project;
use App\Services\Monitoring\Fleet;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Fleet $fleet): Response
    {
        $recentRuns = PipelineRun::query()
            ->with('pipeline.project')
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'project_name' => $run->pipeline->project->name,
                'branch' => $run->pipeline->branch,
                'status' => $run->status,
                'commit_sha' => $run->commit_sha,
                'commit_message' => $run->commit_message,
                'pusher' => $run->pusher,
                'duration_ms' => $run->duration_ms,
                'created_at' => $run->created_at?->toIso8601String(),
            ]);

        $projects = Project::query()
            ->with([
                'pipelines' => function ($q) {
                    $q->with(['latestRun']);
                },
            ])
            ->orderBy('name')
            ->get()
            ->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'repo_name' => $project->repo_full_name ?? $project->repo_name,
                'pipelines' => $project->pipelines->map(fn ($p) => [
                    'id' => $p->id,
                    'branch' => $p->branch,
                    'enabled' => $p->enabled,
                    'last_status' => $p->latestRun?->status,
                ]),
            ]);

        $stats = [
            'total_runs' => PipelineRun::count(),
            'running' => PipelineRun::whereIn('status', ['queued', 'running'])->count(),
            'success' => PipelineRun::where('status', 'success')->count(),
            'failed' => PipelineRun::where('status', 'failed')->count(),
        ];

        return Inertia::render('dashboard', [
            'recentRuns' => $recentRuns,
            'projects' => $projects,
            'stats' => $stats,
            // Servers hold the keys to client machines; only admins see them.
            'fleet' => request()->user()?->hasRole('admin') ? $fleet->servers() : null,
        ]);
    }
}
