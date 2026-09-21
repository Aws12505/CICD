<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\DriftObservation;
use App\Services\Backup\BackupService;
use App\Services\Drift\DriftPoller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Drift and backups: the two things that tell you whether the estate is
 * actually in the state you believe it is.
 */
class OperationsController extends Controller
{
    public function __construct(
        private readonly DriftPoller $drift,
        private readonly BackupService $backups,
    ) {}

    public function index(): Response
    {
        return Inertia::render('operations/index', [
            'drift' => DriftObservation::query()
                ->with('stack.client', 'service')
                ->whereNull('resolution')
                ->latest('observed_at')
                ->get()
                ->map(fn (DriftObservation $d) => [
                    'id' => $d->id,
                    'client' => $d->stack->client->name,
                    'tier' => $d->stack->tier,
                    'service' => $d->service->name,
                    'expected_digest' => $d->expected_digest,
                    'observed_digest' => $d->observed_digest,
                    'missing' => $d->isMissing(),
                    'observed_at' => $d->observed_at?->toIso8601String(),
                ]),

            'backups' => Backup::query()
                ->with('stack.client', 'service')
                ->latest('started_at')
                ->limit(30)
                ->get()
                ->map(fn (Backup $b) => [
                    'id' => $b->id,
                    'client' => $b->stack?->client->name ?? 'Control plane',
                    'tier' => $b->stack?->tier,
                    'service' => $b->service?->name,
                    'kind' => $b->kind,
                    'status' => $b->status,
                    'triggered_by' => $b->triggered_by,
                    'size_bytes' => $b->size_bytes,
                    'tables' => $b->tables,
                    'error' => $b->error,
                    'finished_at' => $b->finished_at?->toIso8601String(),
                ]),

            // A stack whose last successful backup is older than the policy
            // allows. Silent failure is the normal way backups fail.
            'stale' => collect($this->backups->stale())->map(fn ($stack) => [
                'id' => $stack->id,
                'label' => $stack->label(),
            ])->values(),
        ]);
    }

    /** Put back what the control plane says should be running. */
    public function converge(Request $request, DriftObservation $observation): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $this->drift->converge($observation, $request->user(), (string) ($data['reason'] ?? ''));

        return back()->with('success', 'Intent reasserted. It applies on the next release.');
    }

    /**
     * Accept what is running as the new intent.
     *
     * Admin-only and the reason is required — this rewrites what we believe
     * should be deployed, on the strength of someone's judgement.
     */
    public function adopt(Request $request, DriftObservation $observation): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'reason.required' => 'Adopting what is running needs a reason — it changes what we believe is correct.',
        ]);

        $this->drift->adopt($observation, $request->user(), $data['reason']);

        return back()->with('success', 'Adopted as the new intent.');
    }
}
