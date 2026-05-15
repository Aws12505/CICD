<?php

namespace App\Http\Controllers;

use App\Models\PipelineRun;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
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
            ->with(['pipelines' => function ($q) {
                $q->with(['latestRun']);
            }])
            ->orderBy('name')
            ->get()
            ->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'repo_name' => $project->repo_name,
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
        ]);
    }
}
