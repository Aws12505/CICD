<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Guide\SetupGuide;
use App\Services\Stack\EnvResolver;
use App\Services\Stack\PortAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function __construct(
        private readonly PortAllocator $ports,
        private readonly EnvResolver $env,
        private readonly SetupGuide $guide,
    ) {}

    public function index(): Response
    {
        return Inertia::render('clients/index', [
            'clients' => Client::query()
                ->with('stacks.server')
                ->withCount('stacks')
                ->orderBy('name')
                ->get()
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'slug' => $client->slug,
                    'domain' => $client->domain,
                    'status' => $client->status,
                    'stacks' => $client->stacks->map(fn (Stack $s) => [
                        'tier' => $s->tier,
                        'status' => $s->status,
                        'server' => $s->server?->name,
                    ]),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('clients/create', [
            'servers' => Server::query()->orderBy('name')->get(['id', 'name', 'hostname']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Becomes the stacks' compose projects and their MySQL and Redis
            // user names ("acme_testing"), which MySQL caps at 32 characters.
            'slug' => ['required', 'string', 'max:24', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:clients,slug'],
            'domain' => ['required', 'string', 'max:255'],
            'admin_email' => ['nullable', 'email', 'max:255'],
            'admin_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'server_id' => ['required', 'exists:servers,id'],
            // Both tiers land on one server today. This exists so moving
            // testing elsewhere later is a field, not a migration.
            'testing_server_id' => ['nullable', 'exists:servers,id'],
        ]);

        $client = Client::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'domain' => $data['domain'],
            'admin_email' => $data['admin_email'] ?? null,
            'admin_name' => $data['admin_name'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'active',
        ]);

        foreach ([Stack::TIER_LIVE, Stack::TIER_TESTING] as $tier) {
            Stack::create([
                'client_id' => $client->id,
                'tier' => $tier,
                'server_id' => $tier === Stack::TIER_LIVE
                    ? $data['server_id']
                    : ($data['testing_server_id'] ?? $data['server_id']),
                'compose_project' => $client->slug.'_'.$tier,
                'port_range_start' => $this->ports->allocateBlock(),
                'status' => 'pending',
            ]);
        }

        return redirect()->route('clients.show', $client)->with('success', 'Client created with both tiers.');
    }

    public function show(Client $client): Response
    {
        $client->load(['stacks.server', 'stacks.services.service']);

        $services = Service::query()->orderBy('position')->orderBy('name')->get();

        return Inertia::render('clients/show', [
            'steps' => $this->guide->client($client),
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'domain' => $client->domain,
                'status' => $client->status,
                'notes' => $client->notes,
                'admin_email' => $client->admin_email,
            ],
            'services' => $services->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'subdomain' => $s->subdomain,
            ]),
            'stacks' => $client->stacks->map(fn (Stack $stack) => [
                'id' => $stack->id,
                'tier' => $stack->tier,
                'status' => $stack->status,
                'compose_project' => $stack->compose_project,
                'port_range_start' => $stack->port_range_start,
                'server' => $stack->server?->name,
                'entitlements' => $services->map(function (Service $service) use ($stack) {
                    $entitlement = $stack->services->firstWhere('service_id', $service->id);

                    return [
                        'service_id' => $service->id,
                        'enabled' => (bool) $entitlement?->enabled,
                        'edition' => $entitlement?->edition,
                        'host_port' => $entitlement?->host_port,
                        'state' => $entitlement?->state ?? 'pending',
                        'domain' => $stack->domainFor($service),
                        'missing_env' => $entitlement && $entitlement->enabled
                            ? $this->env->missingRequired($entitlement)
                            : [],
                    ];
                }),
            ]),
        ]);
    }

    /**
     * Turn a service on or off for one stack.
     *
     * Only records intent. Nothing appears on the server until a release runs
     * — enabling a service creates databases, certificates and containers, so
     * it is a release event rather than a toggle that takes effect on save.
     */
    public function setEntitlement(Request $request, Stack $stack): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'exists:services,id'],
            'enabled' => ['required', 'boolean'],
            'edition' => ['nullable', 'string', 'max:64'],
        ]);

        $entitlement = StackService::firstOrNew([
            'stack_id' => $stack->id,
            'service_id' => $data['service_id'],
        ]);

        $entitlement->enabled = $data['enabled'];
        // `nullable` leaves the key out of the validated array entirely when
        // the caller omits it, so this cannot index directly.
        $entitlement->edition = ($data['edition'] ?? null) ?: null;
        $entitlement->state ??= 'pending';
        $entitlement->save();

        if ($data['enabled']) {
            $this->ports->assign($entitlement);
        }

        return back()->with('success', 'Entitlement saved. It takes effect on the next release.');
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255'],
            'admin_email' => ['nullable', 'email', 'max:255'],
            'admin_name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'notes' => ['nullable', 'string'],
        ]);

        $client->update($data);

        return back()->with('success', 'Client updated.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $client->delete();

        return redirect()->route('clients.index')->with('success', 'Client removed from the control plane.');
    }

    /**
     * The generated password of the client's first admin, per tier. Admins
     * only; it exists so the first login can happen, and the client is
     * expected to change it.
     */
    public function adminPassword(Client $client): JsonResponse
    {
        return response()->json(
            $client->stacks()->get()->mapWithKeys(fn (Stack $stack) => [
                $stack->tier => $stack->hasSecret('admin_password') ? $stack->secret('admin_password') : null,
            ]),
        );
    }

    /** Suggest a slug from a name, so the two do not drift apart by hand. */
    public static function suggestSlug(string $name): string
    {
        return Str::slug($name, '-');
    }
}
