<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Setting;
use App\Services\GitHub\GitHub;
use App\Services\Guide\SetupGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The few values the whole control plane shares. Secrets are write-only: the
 * page shows whether one is set, never what it is, and leaving a field blank
 * keeps the stored value.
 */
class ControlPlaneSettingsController extends Controller
{
    /** key => [label, help, secret] */
    public const FIELDS = [
        Setting::GITHUB_TOKEN => [
            'GitHub token',
            'Fine-grained token: read access to the service repositories, so the control plane can fetch and build them.',
            true,
        ],
        Setting::GITHUB_WEBHOOK_SECRET => [
            'Webhook secret',
            'Shared by every repository webhook. GitHub signs each delivery with it.',
            true,
        ],
        Setting::REGISTRY_USERNAME => [
            'Registry username',
            'The GitHub user or bot that owns the package tokens below.',
            false,
        ],
        Setting::REGISTRY_PUSH_TOKEN => [
            'Registry push token',
            'write:packages. Used only on the control plane, to push images it builds.',
            true,
        ],
        Setting::REGISTRY_PULL_TOKEN => [
            'Registry pull token',
            'read:packages only. Installed on every client server so it can pull images; it must not be able to push.',
            true,
        ],
        Setting::CONTROL_PLANE_IP => [
            'Extra SSH addresses',
            'Space-separated IPs client firewalls also allow SSH from, such as an office. The control plane itself is always allowed.',
            false,
        ],
        Setting::ACME_EMAIL => [
            'Certificate email',
            "Let's Encrypt sends expiry warnings here.",
            false,
        ],
    ];

    public function edit(): Response
    {
        $fields = [];

        foreach (self::FIELDS as $key => [$label, $help, $secret]) {
            $fields[] = [
                'key' => $key,
                'label' => $label,
                'help' => $help,
                'secret' => $secret,
                'set' => Setting::has($key),
                'value' => $secret ? null : Setting::get($key),
            ];
        }

        return Inertia::render('control-plane/settings', [
            'fields' => $fields,
            // Added to each service repository's webhooks (push events,
            // application/json, the webhook secret above).
            'webhook_url' => route('webhook.build'),
        ]);
    }

    /**
     * Can the saved token read every service repository? One line per
     * service, with the reason when not, so "the first build failed" becomes
     * "pizzasys: the Resource owner must be the organisation".
     */
    public function testGithub(GitHub $github): JsonResponse
    {
        if (! $github->configured()) {
            return response()->json(['configured' => false, 'results' => []]);
        }

        $results = Service::query()->orderBy('position')->orderBy('name')->get()->map(function (Service $service) use ($github) {
            try {
                $check = $github->checkRead($service->repo_full_name, $service->default_branch);
            } catch (\Throwable $e) {
                $check = ['ok' => false, 'problem' => 'error', 'message' => 'Could not reach GitHub: '.$e->getMessage()];
            }

            return [
                'service' => $service->name,
                'repository' => $service->repo_full_name,
                'branch' => $service->default_branch,
            ] + $check;
        })->values();

        return response()->json(['configured' => true, 'results' => $results]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];

        foreach (self::FIELDS as $key => $field) {
            $rules[$key] = ['nullable', 'string', 'max:2000'];
        }

        $rules[Setting::ACME_EMAIL][] = 'email';
        $rules['clear'] = ['array'];
        $rules['clear.*'] = ['string'];

        $data = $request->validate($rules);
        $clear = array_intersect($data['clear'] ?? [], array_keys(self::FIELDS));

        foreach (self::FIELDS as $key => [, , $secret]) {
            if (in_array($key, $clear, true)) {
                Setting::put($key, null);

                continue;
            }

            $value = trim((string) ($data[$key] ?? ''));

            // A blank secret means "keep it"; a blank plain value means "clear it".
            if ($secret && $value === '') {
                continue;
            }

            Setting::put($key, $value === '' ? null : $value);
        }

        SetupGuide::forget();

        return back()->with('success', 'Settings saved.');
    }
}
