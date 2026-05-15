<?php

namespace App\Http\Controllers;

use App\Jobs\ExecutePipelineJob;
use App\Models\Pipeline;
use App\Models\PipelineRun;
use App\Services\GitHubSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private GitHubSignatureService $signatures)
    {
    }

    public function github(Request $request): JsonResponse
    {
        $event = $request->header('X-GitHub-Event');

        if ($event === 'ping') {
            return response()->json(['ok' => true, 'pong' => true]);
        }

        if ($event !== 'push') {
            return response()->json(['ignored' => true, 'event' => $event], 202);
        }

        $payload = $request->all();
        $repoFullName = data_get($payload, 'repository.full_name');
        $repoName = data_get($payload, 'repository.name');
        $repoOwner = data_get($payload, 'repository.owner.login')
            ?: data_get($payload, 'repository.owner.name');
        $ref = data_get($payload, 'ref', '');
        $branch = str_replace('refs/heads/', '', $ref);
        $resolvedFullName = $repoFullName ?: ($repoOwner && $repoName ? "{$repoOwner}/{$repoName}" : null);

        if (!$resolvedFullName || !$branch) {
            return response()->json(['error' => 'Missing repository full name or branch'], 400);
        }

        $pipeline = Pipeline::query()
            ->with('project')
            ->whereHas('project', fn($q) => $q->where('repo_full_name', $resolvedFullName))
            ->where('branch', $branch)
            ->where('enabled', true)
            ->first();

        if (!$pipeline) {
            Log::info('No pipeline configured', ['repo' => $resolvedFullName, 'branch' => $branch]);

            return response()->json([
                'error' => "No enabled pipeline for {$resolvedFullName}:{$branch}",
            ], 404);
        }

        $signature = $request->header('X-Hub-Signature-256');
        $rawBody = $request->getContent();

        $secret = $pipeline->project->getRawOriginal('github_secret');

        if (!$secret) {
            Log::warning('Missing project webhook secret', ['project_id' => $pipeline->project->id]);

            return response()->json(['error' => 'Webhook secret not configured'], 422);
        }

        if (!$this->signatures->verify($secret, $rawBody, $signature)) {
            Log::warning('Invalid webhook signature', ['pipeline_id' => $pipeline->id]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $head = data_get($payload, 'head_commit', []);

        $run = PipelineRun::create([
            'pipeline_id' => $pipeline->id,
            'status' => 'queued',
            'commit_sha' => data_get($head, 'id') ?: data_get($payload, 'after'),
            'commit_message' => data_get($head, 'message'),
            'pusher' => data_get($payload, 'pusher.name') ?: data_get($payload, 'sender.login'),
            'triggered_by' => 'webhook',
            'log_path' => $this->buildLogPath($pipeline, $branch),
        ]);

        ExecutePipelineJob::dispatch($run->id);

        return response()->json([
            'ok' => true,
            'run_id' => $run->id,
        ], 202);
    }

    private function buildLogPath(Pipeline $pipeline, string $branch): string
    {
        $safe = fn($v) => preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $v);

        return 'logs/pipeline-runs/' . $safe($pipeline->project->repo_full_name ?? $pipeline->project->repo_name)
            . '/' . $safe($branch)
            . '/' . now()->format('Ymd-His') . '-' . uniqid() . '.log';
    }
}
