<?php

namespace App\Http\Controllers;

use App\Jobs\BuildJob;
use App\Models\Build;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Build\ImageBuilder;
use App\Services\GitHubSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GitHub push → build (decision 18). Never deploys.
 *
 * A push to a service's default branch builds the template; a push to
 * `edition/<name>` builds that edition. Every other branch is ignored.
 *
 * One webhook secret for every repository, from Settings. The signature is
 * checked before anything is looked up, and every rejection looks the same,
 * so the endpoint does not reveal which repositories are registered.
 */
class BuildWebhookController extends Controller
{
    public function __construct(
        private readonly GitHubSignatureService $signatures,
        private readonly ImageBuilder $builder,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = Setting::get(Setting::GITHUB_WEBHOOK_SECRET);

        if (blank($secret) || ! $this->signatures->verify($secret, $request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $event = $request->header('X-GitHub-Event');

        if ($event === 'ping') {
            return response()->json(['ok' => true]);
        }

        if ($event !== 'push' || $request->boolean('deleted')) {
            return response()->json(['ignored' => true], 202);
        }

        $repository = (string) $request->input('repository.full_name');
        $branch = str_replace('refs/heads/', '', (string) $request->input('ref'));
        $sha = (string) $request->input('after');

        if (! preg_match('/^[0-9a-f]{40}$/', $sha)) {
            return response()->json(['ignored' => true], 202);
        }

        $builds = [];

        $services = Service::query()->whereRaw('lower(repo_full_name) = ?', [strtolower($repository)])->get();

        foreach ($services as $service) {
            $edition = Build::editionFor($branch);

            if ($branch !== $service->default_branch && $edition === null) {
                continue;
            }

            foreach (self::buildsFor($service, $branch) as $stack) {
                $build = $this->builder->create($service, $branch, $sha, $stack, Build::TRIGGER_WEBHOOK);
                BuildJob::dispatch($build->id);
                $builds[] = $build->id;
            }
        }

        return response()->json(['builds' => $builds], 202);
    }

    /**
     * One shared build, or for a per-stack service one per stack running
     * that branch's edition.
     *
     * @return list<Stack|null>
     */
    public static function buildsFor(Service $service, string $branch): array
    {
        if (! $service->per_stack_build) {
            return [null];
        }

        $edition = Build::editionFor($branch);

        return StackService::query()
            ->with('stack')
            ->where('service_id', $service->id)
            ->where('enabled', true)
            ->get()
            ->filter(fn (StackService $s) => ($s->edition ?: null) === $edition)
            ->map(fn (StackService $s) => $s->stack)
            ->values()
            ->all();
    }
}
