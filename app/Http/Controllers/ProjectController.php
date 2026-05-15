<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        $projects = Project::query()
            ->withCount('pipelines')
            ->orderBy('name')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'repo_owner' => $p->repo_owner,
                'repo_name' => $p->repo_name,
                'repo_full_name' => $p->repo_full_name,
                'description' => $p->description,
                'pipelines_count' => $p->pipelines_count,
                'created_at' => $p->created_at?->toIso8601String(),
            ]);

        return Inertia::render('projects/index', [
            'projects' => $projects,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('projects/create', [
            'generatedSecret' => Str::random(40),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'repo_owner' => 'required|string|max:255|regex:/^[A-Za-z0-9_.-]+$/',
            'repo_name' => 'required|string|max:255|regex:/^[A-Za-z0-9_.-]+$/',
            'description' => 'nullable|string',
            'github_secret' => 'required|string|min:8|max:255',
        ]);

        $data['repo_full_name'] = $this->buildFullName($data['repo_owner'], $data['repo_name']);

        Validator::make($data, [
            'repo_full_name' => 'unique:projects,repo_full_name',
        ], [
            'repo_full_name.unique' => 'This repository is already configured.',
        ])->validate();

        Project::create($data);

        return redirect()->route('projects.index')->with('success', 'Project created.');
    }

    public function show(Project $project): Response
    {
        $project->load(['pipelines' => fn($q) => $q->withCount('runs')->orderBy('branch')]);

        return Inertia::render('projects/show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'repo_owner' => $project->repo_owner,
                'repo_name' => $project->repo_name,
                'repo_full_name' => $project->repo_full_name,
                'description' => $project->description,
                'github_secret' => $project->getRawOriginal('github_secret'),
            ],
            'pipelines' => $project->pipelines->map(fn($p) => [
                'id' => $p->id,
                'branch' => $p->branch,
                'enabled' => $p->enabled,
                'steps_count' => count($p->steps ?? []),
                'runs_count' => $p->runs_count,
            ]),
        ]);
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('projects/edit', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'repo_owner' => $project->repo_owner,
                'repo_name' => $project->repo_name,
                'repo_full_name' => $project->repo_full_name,
                'description' => $project->description,
                'github_secret' => $project->getRawOriginal('github_secret'),
            ],
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'repo_owner' => 'required|string|max:255|regex:/^[A-Za-z0-9_.-]+$/',
            'repo_name' => 'required|string|max:255|regex:/^[A-Za-z0-9_.-]+$/',
            'description' => 'nullable|string',
            'github_secret' => 'required|string|min:8|max:255',
        ]);

        $data['repo_full_name'] = $this->buildFullName($data['repo_owner'], $data['repo_name']);

        Validator::make($data, [
            'repo_full_name' => 'unique:projects,repo_full_name,' . $project->id,
        ], [
            'repo_full_name.unique' => 'This repository is already configured.',
        ])->validate();

        $project->update($data);

        return redirect()->route('projects.show', $project)->with('success', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $project->delete();

        return redirect()->route('projects.index')->with('success', 'Project deleted.');
    }

    private function buildFullName(string $owner, string $repo): string
    {
        return trim($owner, '/') . '/' . trim($repo, '/');
    }
}
