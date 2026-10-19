<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Services are defined here rather than by a manifest in each repo, so adding
 * the thirteenth service is a form rather than a commit.
 */
class ServiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('services/index', [
            'services' => Service::query()
                ->withCount(['envDefinitions', 'stackServices as enabled_count' => fn ($q) => $q->where('enabled', true)])
                ->orderBy('position')
                ->orderBy('name')
                ->get()
                ->map(fn (Service $s) => $this->summarise($s)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('services/create', ['recipes' => $this->recipes()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $service = Service::create($this->validated($request));

        return redirect()->route('services.show', $service)->with('success', 'Service added.');
    }

    public function show(Service $service): Response
    {
        $service->load('envDefinitions');

        return Inertia::render('services/show', [
            'service' => $this->summarise($service) + [
                'repo_full_name' => $service->repo_full_name,
                'default_branch' => $service->default_branch,
                'dockerfile_path' => $service->dockerfile_path,
                'database_name' => $service->database_name,
                'health_path' => $service->health_path,
                'nats_publishes' => $service->nats_publishes ?? [],
                'nats_consumes' => $service->nats_consumes ?? [],
                'image_repository' => $service->imageRepository(),
                'auth_service_name' => $service->auth_service_name,
                'extra_databases' => $service->extra_databases ?? [],
                'processes' => $service->processes ?? [],
                'first_run_commands' => $service->first_run_commands ?? [],
                'every_deploy_commands' => $service->every_deploy_commands ?? [],
            ],
            'definitions' => $service->envDefinitions->map(fn (ServiceEnvDefinition $d) => [
                'id' => $d->id,
                'key' => $d->key,
                'source' => $d->source,
                'required' => $d->required,
                'is_build_arg' => $d->is_build_arg,
                'default_value' => $d->default_value,
                'description' => $d->description,
            ]),
            'recipes' => $this->recipes(),
            'sources' => $this->sources(),
        ]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $service->update($this->validated($request, $service));

        return back()->with('success', 'Service updated.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        return redirect()->route('services.index')->with('success', 'Service removed.');
    }

    /**
     * How a service runs on a stack: its pizzasys name, its extra databases,
     * the processes beside the web container, and the commands a deploy runs.
     */
    public function updateCatalog(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'auth_service_name' => ['nullable', 'string', 'max:64'],
            'extra_databases' => ['array'],
            'extra_databases.*' => ['string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'processes' => ['array'],
            'processes.*.name' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9-]+$/', 'distinct'],
            'processes.*.command' => ['required', 'string', 'max:500'],
            'first_run_commands' => ['array'],
            'first_run_commands.*' => ['string', 'max:500'],
            'every_deploy_commands' => ['array'],
            'every_deploy_commands.*' => ['string', 'max:500'],
        ], [
            'extra_databases.*.regex' => 'Database names are lowercase letters, digits and underscores.',
            'processes.*.name.regex' => 'Process names are lowercase letters, digits and dashes.',
        ]);

        $service->update([
            'auth_service_name' => $data['auth_service_name'] ?? null,
            'extra_databases' => array_values($data['extra_databases'] ?? []),
            'processes' => array_values($data['processes'] ?? []),
            'first_run_commands' => array_values($data['first_run_commands'] ?? []),
            'every_deploy_commands' => array_values($data['every_deploy_commands'] ?? []),
        ]);

        return back()->with('success', 'Saved. It takes effect on each client\'s next deploy.');
    }

    /** Add or replace one environment key on a service. */
    public function storeDefinition(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:128', 'regex:/^[A-Z_][A-Z0-9_]*$/'],
            'source' => ['required', Rule::in(array_keys($this->sources()))],
            'required' => ['required', 'boolean'],
            'is_build_arg' => ['required', 'boolean'],
            'default_value' => ['nullable', 'string'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'key.regex' => 'Environment keys are upper snake case, e.g. AUTH_SERVER_BASE_URL.',
        ]);

        ServiceEnvDefinition::updateOrCreate(
            ['service_id' => $service->id, 'key' => $data['key']],
            $data,
        );

        return back()->with('success', "{$data['key']} saved.");
    }

    public function destroyDefinition(Service $service, ServiceEnvDefinition $definition): RedirectResponse
    {
        abort_unless($definition->service_id === $service->id, 404);

        $definition->delete();

        return back()->with('success', 'Environment key removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'slug' => $service->slug,
            'subdomain' => $service->subdomain,
            'build_recipe' => $service->build_recipe,
            'per_stack_build' => $service->per_stack_build,
            'position' => $service->position,
            'env_definitions_count' => $service->env_definitions_count ?? $service->envDefinitions()->count(),
            'enabled_count' => $service->enabled_count ?? 0,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function recipes(): array
    {
        return [
            Service::RECIPE_LARAVEL_API => 'Laravel API',
            Service::RECIPE_LARAVEL_WORKER => 'Laravel API + worker',
            Service::RECIPE_NEXTJS => 'Next.js',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        return [
            ServiceEnvDefinition::SOURCE_LITERAL => 'Typed in per stack',
            ServiceEnvDefinition::SOURCE_SECRET => 'Secret, typed in per stack',
            ServiceEnvDefinition::SOURCE_GENERATED => 'Generated once per stack',
            ServiceEnvDefinition::SOURCE_SERVICE_URL => 'Another service\'s URL',
            ServiceEnvDefinition::SOURCE_STACK => 'Derived from the stack',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Service $service = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('services', 'slug')->ignore($service?->id)],
            'repo_full_name' => ['required', 'string', 'max:255'],
            'default_branch' => ['required', 'string', 'max:128'],
            'build_recipe' => ['required', Rule::in(array_keys($this->recipes()))],
            'dockerfile_path' => ['nullable', 'string', 'max:255'],
            'subdomain' => ['required', 'string', 'max:64'],
            'database_name' => ['nullable', 'string', 'max:64'],
            'health_path' => ['required', 'string', 'max:255'],
            'per_stack_build' => ['required', 'boolean'],
            'nats_publishes' => ['nullable', 'array'],
            'nats_consumes' => ['nullable', 'array'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
