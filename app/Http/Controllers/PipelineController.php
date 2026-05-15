<?php

namespace App\Http\Controllers;

use App\Models\Pipeline;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function create(Project $project): Response
    {
        return Inertia::render('pipelines/edit', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'repo_name' => $project->repo_name,
                'repo_full_name' => $project->repo_full_name,
            ],
            'pipeline' => null,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $this->validatePipeline($request, $project->id);

        $project->pipelines()->create($data);

        return redirect()->route('projects.show', $project)->with('success', 'Pipeline created.');
    }

    public function edit(Pipeline $pipeline): Response
    {
        $pipeline->load('project');

        return Inertia::render('pipelines/edit', [
            'project' => [
                'id' => $pipeline->project->id,
                'name' => $pipeline->project->name,
                'repo_name' => $pipeline->project->repo_name,
                'repo_full_name' => $pipeline->project->repo_full_name,
            ],
            'pipeline' => [
                'id' => $pipeline->id,
                'branch' => $pipeline->branch,
                'enabled' => $pipeline->enabled,
                'steps' => $pipeline->steps ?? [],
            ],
        ]);
    }

    public function update(Request $request, Pipeline $pipeline): RedirectResponse
    {
        $data = $this->validatePipeline($request, $pipeline->project_id, $pipeline->id);

        $pipeline->update($data);

        return redirect()->route('projects.show', $pipeline->project_id)->with('success', 'Pipeline saved.');
    }

    public function destroy(Pipeline $pipeline): RedirectResponse
    {
        $projectId = $pipeline->project_id;
        $pipeline->delete();

        return redirect()->route('projects.show', $projectId)->with('success', 'Pipeline deleted.');
    }

    private function validatePipeline(Request $request, int $projectId, ?int $ignoreId = null): array
    {
        $unique = 'unique:pipelines,branch,' . ($ignoreId ?? 'NULL') . ',id,project_id,' . $projectId;

        return $request->validate([
            'branch' => ['required', 'string', 'max:255', $unique],
            'enabled' => ['boolean'],
            'steps' => ['array'],
            'steps.*.id' => ['required', 'string'],
            'steps.*.name' => ['required', 'string', 'max:255'],
            'steps.*.command' => ['required', 'string'],
            'steps.*.cwd' => ['nullable', 'string', 'max:1024'],
            'steps.*.timeout' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'steps.*.env' => ['nullable', 'array'],
        ]);
    }
}
