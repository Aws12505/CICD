<?php

namespace App\Http\Controllers;

use App\Jobs\ExecutePipelineJob;
use App\Models\PipelineRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    public function index(): Response
    {
        $runs = PipelineRun::query()
            ->with('pipeline.project')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn ($run) => $this->transformRun($run));

        return Inertia::render('runs/index', [
            'runs' => $runs,
        ]);
    }

    public function show(PipelineRun $run): Response
    {
        $run->load(['pipeline.project', 'stepResults']);

        $logPath = storage_path($run->log_path);
        $log = File::exists($logPath) ? File::get($logPath) : '';

        return Inertia::render('runs/show', [
            'run' => $this->transformRun($run),
            'steps' => $run->stepResults->map(fn ($s) => [
                'id' => $s->id,
                'step_index' => $s->step_index,
                'name' => $s->name,
                'command' => $s->command,
                'status' => $s->status,
                'exit_code' => $s->exit_code,
                'duration_ms' => $s->duration_ms,
            ]),
            'log' => $log,
        ]);
    }

    public function rerun(PipelineRun $run): RedirectResponse
    {
        $pipeline = $run->pipeline;

        $newRun = PipelineRun::create([
            'pipeline_id' => $pipeline->id,
            'status' => 'queued',
            'commit_sha' => $run->commit_sha,
            'commit_message' => $run->commit_message,
            'pusher' => 'manual-rerun',
            'triggered_by' => 'rerun',
            'log_path' => 'logs/pipeline-runs/'
                .preg_replace('/[^A-Za-z0-9._-]/', '-', $pipeline->project->repo_name)
                .'/'.preg_replace('/[^A-Za-z0-9._-]/', '-', $pipeline->branch)
                .'/'.now()->format('Ymd-His').'-'.uniqid().'.log',
        ]);

        ExecutePipelineJob::dispatch($newRun->id);

        return redirect()->route('runs.show', $newRun)->with('success', 'Rerun started.');
    }

    private function transformRun(PipelineRun $run): array
    {
        return [
            'id' => $run->id,
            'pipeline_id' => $run->pipeline_id,
            'project_name' => $run->pipeline->project->name,
            'repo_name' => $run->pipeline->project->repo_name,
            'branch' => $run->pipeline->branch,
            'status' => $run->status,
            'commit_sha' => $run->commit_sha,
            'commit_message' => $run->commit_message,
            'pusher' => $run->pusher,
            'triggered_by' => $run->triggered_by,
            'started_at' => optional($run->started_at)->toIso8601String(),
            'finished_at' => optional($run->finished_at)->toIso8601String(),
            'duration_ms' => $run->duration_ms,
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
