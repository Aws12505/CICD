<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceFile;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Build\BuildFiles;
use App\Services\Stack\StackRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A service's build and run files (decision 27): see each one, where it comes
 * from, change it, put it back, or point it at the repository.
 */
class ServiceFileController extends Controller
{
    public function __construct(
        private readonly BuildFiles $files,
        private readonly StackRenderer $renderer,
    ) {}

    /**
     * The card on the service page: one line per file.
     *
     * @return list<array<string, mixed>>
     */
    public static function summary(Service $service, BuildFiles $files): array
    {
        $service->loadMissing('files');

        return array_map(fn (string $name) => [
            'name' => $name,
            'description' => BuildFiles::DESCRIPTIONS[$name] ?? '',
            'template' => $files->isTemplate($name),
            'buildFile' => in_array($name, BuildFiles::BUILD_FILES, true),
        ] + $files->source($service, $name), $files->names($service));
    }

    public function show(Service $service, string $name): Response
    {
        $this->assertKnown($service, $name);
        $source = $this->files->source($service, $name);
        $custom = $service->files->firstWhere('name', $name);

        $stacks = StackService::query()
            ->with('stack.client')
            ->where('service_id', $service->id)
            ->where('enabled', true)
            ->get()
            ->map(fn (StackService $s) => ['id' => $s->stack_id, 'label' => $s->stack->label()])
            ->values();

        return Inertia::render('services/file', [
            'service' => ['name' => $service->name, 'slug' => $service->slug],
            'file' => [
                'name' => $name,
                'description' => BuildFiles::DESCRIPTIONS[$name] ?? '',
                'source' => $source['source'],
                'repo_path' => $source['repo_path'],
                'contents' => $source['source'] === ServiceFile::SOURCE_CUSTOM ? (string) $custom?->contents : ($this->files->defaultContents($service, $name) ?? ''),
                'default_contents' => $this->files->defaultContents($service, $name) ?? '',
                'template' => $this->files->isTemplate($name),
                'placeholders' => $this->files->placeholdersFor($name),
                'buildFile' => in_array($name, BuildFiles::BUILD_FILES, true),
                // A repository Dockerfile does not copy the control plane's
                // entrypoint, so that file has no effect then.
                'inert' => $name === BuildFiles::ENTRYPOINT
                    && $this->files->source($service, BuildFiles::DOCKERFILE)['source'] === ServiceFile::SOURCE_REPO,
            ],
            'stacks' => $stacks,
        ]);
    }

    public function update(Request $request, Service $service, string $name): RedirectResponse
    {
        $this->assertKnown($service, $name);

        $data = $request->validate([
            'source' => ['required', Rule::in([ServiceFile::SOURCE_DEFAULT, ServiceFile::SOURCE_CUSTOM, ServiceFile::SOURCE_REPO])],
            'contents' => ['nullable', 'string', 'max:200000'],
            'repo_path' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9_./-]+$#', 'not_regex:#(^|/)\.\.(/|$)#'],
        ]);

        if ($data['source'] === ServiceFile::SOURCE_DEFAULT) {
            $service->files()->where('name', $name)->delete();

            return back()->with('success', "{$name} is back to the control plane's default.");
        }

        if ($data['source'] === ServiceFile::SOURCE_REPO) {
            if (! in_array($name, BuildFiles::BUILD_FILES, true)) {
                return back()->withErrors(['source' => "{$name} is rendered by the control plane; it cannot come from the repository."]);
            }

            if (blank($data['repo_path'] ?? null)) {
                return back()->withErrors(['repo_path' => 'Which file in the repository?']);
            }

            $service->files()->updateOrCreate(['name' => $name], ['source' => ServiceFile::SOURCE_REPO, 'repo_path' => ltrim($data['repo_path'], '/'), 'contents' => null]);

            return back()->with('success', "{$name} now comes from the repository at {$data['repo_path']}. It takes effect on the next build.");
        }

        $contents = str_replace("\r\n", "\n", (string) ($data['contents'] ?? ''));

        if (trim($contents) === '') {
            return back()->withErrors(['contents' => 'The file is empty. Use "Reset to default" to stop overriding it.']);
        }

        if ($problems = $this->files->validate($name, $contents)) {
            return back()->withErrors(['contents' => implode(' ', $problems)]);
        }

        $service->files()->updateOrCreate(['name' => $name], ['source' => ServiceFile::SOURCE_CUSTOM, 'contents' => $contents, 'repo_path' => null]);

        return back()->with('success', $this->files->isTemplate($name)
            ? "{$name} saved. It is used on the next deploy or Apply of each stack."
            : "{$name} saved. It is used on the next build.");
    }

    /**
     * The compose file and nginx vhost a stack would get with the files as
     * they are now, so an edit can be checked before any deploy. Rendering
     * allocates ports as a first deploy would; that is stable and harmless.
     */
    public function preview(Service $service, Stack $stack): JsonResponse
    {
        $entitlement = StackService::query()
            ->with('service.files', 'stack.client', 'stack.server')
            ->where('service_id', $service->id)
            ->where('stack_id', $stack->id)
            ->where('enabled', true)
            ->first();

        abort_if($entitlement === null, 404, "{$service->name} is not enabled on that stack.");

        return response()->json([
            'compose' => $this->renderer->compose($stack),
            'nginx' => $this->renderer->nginx($entitlement),
        ]);
    }

    private function assertKnown(Service $service, string $name): void
    {
        abort_unless(in_array($name, $this->files->names($service), true), 404);
    }
}
