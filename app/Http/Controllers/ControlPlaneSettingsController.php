<?php

namespace App\Http\Controllers;

use App\Models\Setting;
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

        return Inertia::render('control-plane/settings', ['fields' => $fields]);
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

        return back()->with('success', 'Settings saved.');
    }
}
