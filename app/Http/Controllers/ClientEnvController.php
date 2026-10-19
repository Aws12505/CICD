<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyStackJob;
use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\Service;
use App\Models\ServiceEnvDefinition;
use App\Models\Stack;
use App\Models\StackEnvValue;
use App\Models\StackService;
use App\Models\Task;
use App\Services\Stack\EnvResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A client's environment, in one screen: values for both tiers, overrides per
 * tier, and what each service ends up with.
 *
 * Secrets are write-only. The screen says a secret is set, never what it is,
 * and an empty secret field on save keeps the stored value.
 */
class ClientEnvController extends Controller
{
    public const SCOPE_CLIENT = 'client';

    public function __construct(private readonly EnvResolver $env) {}

    public function edit(Client $client): Response
    {
        $client->load('stacks.server');
        $stacks = $client->stacks->sortBy(fn (Stack $s) => $s->isLive() ? 1 : 0)->values();

        $entitlements = StackService::query()
            ->with('service.envDefinitions', 'stack.client', 'stack.server')
            ->whereIn('stack_id', $stacks->pluck('id'))
            ->where('enabled', true)
            ->get();

        $services = $entitlements->pluck('service')->unique('id')->sortBy('position')->values();

        return Inertia::render('clients/env', [
            'client' => ['id' => $client->id, 'name' => $client->name, 'slug' => $client->slug],
            'scopes' => [
                ['key' => self::SCOPE_CLIENT, 'label' => 'Both tiers'],
                ...$stacks->map(fn (Stack $s) => ['key' => $s->tier, 'label' => ucfirst($s->tier).' only']),
            ],
            'services' => $services->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'slug' => $service->slug,
                'definitions' => $service->envDefinitions->map(fn (ServiceEnvDefinition $d) => [
                    'key' => $d->key,
                    'source' => $d->source,
                    'required' => $d->required,
                    'is_build_arg' => $d->is_build_arg,
                    'description' => $d->description,
                    'default_value' => $d->isDerived() || EnvResolver::isSecretDefinition($d) ? null : $d->default_value,
                    'derived' => $d->isDerived(),
                    'secret' => EnvResolver::isSecretDefinition($d),
                ])->values(),
            ])->values(),
            'values' => [
                ...$client->settings()->get()->map(fn (ClientSetting $row) => $this->row(self::SCOPE_CLIENT, $row)),
                ...StackEnvValue::query()->whereIn('stack_id', $stacks->pluck('id'))->get()
                    ->map(fn (StackEnvValue $row) => $this->row($stacks->firstWhere('id', $row->stack_id)->tier, $row)),
            ],
            'stacks' => $stacks->map(fn (Stack $stack) => [
                'id' => $stack->id,
                'tier' => $stack->tier,
                'status' => $stack->status,
                'deployed' => $entitlements->where('stack_id', $stack->id)->contains(fn (StackService $s) => filled($s->current_digest)),
                'services' => $entitlements->where('stack_id', $stack->id)->mapWithKeys(fn (StackService $s) => [
                    $s->service->slug => [
                        'missing' => $this->env->missingRequired($s),
                        'resolved' => $this->preview($s),
                    ],
                ]),
            ])->values(),
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $stacks = $client->stacks()->get()->keyBy('tier');

        $data = $request->validate([
            'entries' => ['required', 'array', 'max:500'],
            'entries.*.scope' => ['required', Rule::in([self::SCOPE_CLIENT, ...$stacks->keys()])],
            'entries.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'entries.*.key' => ['required', 'string', 'max:128', 'regex:/^[A-Z_][A-Z0-9_]*$/'],
            'entries.*.value' => ['nullable', 'string', 'max:20000'],
            'entries.*.is_secret' => ['boolean'],
            'entries.*.remove' => ['boolean'],
        ], [
            'entries.*.key.regex' => 'Environment keys are upper snake case, e.g. HUMANITY_API_KEY.',
        ]);

        $changed = 0;

        foreach ($data['entries'] as $entry) {
            $query = $entry['scope'] === self::SCOPE_CLIENT
                ? ClientSetting::query()->where('client_id', $client->id)
                : StackEnvValue::query()->where('stack_id', $stacks[$entry['scope']]->id);

            $existing = $query
                ->where('key', $entry['key'])
                ->where(fn ($q) => isset($entry['service_id'])
                    ? $q->where('service_id', $entry['service_id'])
                    : $q->whereNull('service_id'))
                ->first();

            $value = (string) ($entry['value'] ?? '');
            $secret = (bool) ($entry['is_secret'] ?? false);

            if (($entry['remove'] ?? false) || ($value === '' && ! $secret)) {
                // Empty means "inherit", so the row goes rather than storing ''.
                $existing?->delete();
                $changed++;

                continue;
            }

            if ($value === '' && $secret) {
                // A blank secret keeps what is stored.
                $existing?->update(['is_secret' => true]);

                continue;
            }

            $attributes = ['value' => $value, 'is_secret' => $secret];

            if ($existing) {
                $existing->update($attributes);
            } else {
                $this->newRow($entry['scope'], $client, $stacks, $entry)->fill($attributes)->save();
            }

            $changed++;
        }

        return back()->with('success', $changed === 0
            ? 'Nothing changed.'
            : 'Saved. Click Apply on a tier to restart what reads these values.');
    }

    /** Apply: put the saved values into effect on one tier. */
    public function apply(Request $request, Stack $stack): RedirectResponse
    {
        $stack->load('server', 'client');

        if (! $stack->server?->isProvisioned()) {
            return back()->with('error', 'Set up the server first.');
        }

        $task = Task::begin(Task::TYPE_APPLY, "Apply settings to {$stack->label()}", $stack, $request->user());

        ApplyStackJob::dispatch($stack->id, $task->id);

        return redirect()->route('tasks.show', $task);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $scope, ClientSetting|StackEnvValue $row): array
    {
        return [
            'scope' => $scope,
            'service_id' => $row->service_id,
            'key' => $row->key,
            'value' => $row->is_secret ? null : $row->value,
            'is_secret' => (bool) $row->is_secret,
        ];
    }

    /**
     * What a service will get, with credentials masked.
     *
     * @return array<string, array{value: string, origin: string}>
     */
    private function preview(StackService $entitlement): array
    {
        $resolved = $this->env->resolve($entitlement);
        $origins = $this->env->origins($entitlement);

        $secretKeys = $entitlement->service->envDefinitions
            ->filter(fn (ServiceEnvDefinition $d) => EnvResolver::isSecretDefinition($d))
            ->pluck('key')
            ->merge(ClientSetting::query()->where('client_id', $entitlement->stack->client_id)->where('is_secret', true)->pluck('key'))
            ->merge(StackEnvValue::query()->where('stack_id', $entitlement->stack_id)->where('is_secret', true)->pluck('key'))
            ->flip();

        $preview = [];

        foreach ($resolved as $key => $value) {
            $preview[$key] = [
                'value' => $secretKeys->has($key) ? '••••••••' : $value,
                'origin' => $origins[$key] ?? 'definition',
            ];
        }

        ksort($preview);

        return $preview;
    }

    private function newRow(string $scope, Client $client, $stacks, array $entry): Model
    {
        $owner = $scope === self::SCOPE_CLIENT
            ? new ClientSetting(['client_id' => $client->id])
            : new StackEnvValue(['stack_id' => $stacks[$scope]->id]);

        return $owner->fill([
            'service_id' => $entry['service_id'] ?? null,
            'key' => $entry['key'],
        ]);
    }
}
