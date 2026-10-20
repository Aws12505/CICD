<?php

namespace App\Http\Middleware;

use App\Services\Guide\SetupGuide;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Shared so the UI can hide controls the user cannot use.
                // This is presentation only — the routes are what actually
                // enforce access, and they do it regardless of what is sent
                // here.
                'roles' => $request->user()?->getRoleNames()->values()->all() ?? [],
                'can' => [
                    'manage' => (bool) $request->user()?->hasAnyRole([Roles::ADMIN, Roles::OPERATOR]),
                    'destroy' => (bool) $request->user()?->hasRole(Roles::ADMIN),
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // The one thing to do next, shown in every page's header. Cached
            // briefly; the actions that change it clear the cache.
            'nextStep' => $request->user()
                ? Cache::remember(SetupGuide::CACHE_KEY, 20, fn () => app(SetupGuide::class)->next())
                : null,
        ];
    }
}
