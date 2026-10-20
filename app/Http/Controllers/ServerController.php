<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionServerJob;
use App\Models\Server;
use App\Models\Stack;
use App\Models\Task;
use App\Services\Guide\SetupGuide;
use App\Services\Remote\ServerProbe;
use App\Support\SshKeypair;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Servers, and the three clicks that make one usable (decision 17):
 *
 *  1. Add — the control plane generates a keypair and shows the one line to
 *     paste into the VPS provider's web console. The private half never
 *     leaves this database.
 *  2. Verify — pin the host key, log in, record what is installed.
 *  3. Set up server — the provisioning run, queued and streamed to a task.
 */
class ServerController extends Controller
{
    public function __construct(private readonly ServerProbe $probe) {}

    public function index(): Response
    {
        return Inertia::render('servers/index', [
            'servers' => Server::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Server $server) => $this->summarise($server)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('servers/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $keypair = SshKeypair::generate('control-plane@'.$data['hostname']);

        $server = Server::create($data + [
            'status' => 'pending',
            'ssh_private_key' => $keypair->privateKey,
            'public_key' => $keypair->publicKey,
        ]);

        // Reading the host key needs no login, so it can be pinned before the
        // public key is even pasted.
        $this->probe->captureHostKey($server);

        return redirect()
            ->route('servers.show', $server)
            ->with('success', 'Server added. Paste the command below into the server, then click Verify.');
    }

    public function show(Server $server): Response
    {
        $provisioning = $this->provisionTasks($server)->latest('id')->first();

        return Inertia::render('servers/show', [
            'server' => $this->summarise($server) + [
                'notes' => $server->notes,
                'last_error' => $server->last_error,
                'public_key' => $server->public_key,
                'authorize_command' => $server->authorizeCommand(),
                'capabilities' => $server->capabilities,
                'provisioned_at' => $server->provisioned_at?->toIso8601String(),
                'stacks' => $server->stacks()->with('client')->get()->map(fn (Stack $stack) => [
                    'id' => $stack->id,
                    'label' => $stack->label(),
                    'status' => $stack->status,
                ]),
                'last_provision' => $provisioning ? TaskController::summarise($provisioning) : null,
            ],
            'actions' => collect(ServerOpsController::ACTIONS)->map(fn ($a, $key) => ['key' => $key, 'label' => $a[0]])->values(),
        ]);
    }

    public function edit(Server $server): Response
    {
        return Inertia::render('servers/edit', [
            'server' => $this->summarise($server) + ['notes' => $server->notes],
        ]);
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $data = $this->validated($request, $server);

        $reachabilityChanged = $server->hostname !== $data['hostname']
            || $server->ssh_port !== (int) $data['ssh_port'];

        $server->update($data);

        if ($reachabilityChanged) {
            // The pinned key belongs to the old host:port. Keeping it would
            // either reject the new host or, worse, silently match something
            // else.
            $server->forceFill(['host_key' => null, 'status' => 'pending'])->save();
            $this->probe->captureHostKey($server);
        }

        return redirect()
            ->route('servers.show', $server)
            ->with('success', 'Server updated.');
    }

    public function destroy(Server $server): RedirectResponse
    {
        if ($server->stacks()->exists()) {
            return back()->with('error', 'Move or remove the stacks on this server first.');
        }

        $server->delete();

        return redirect()->route('servers.index')->with('success', 'Server removed.');
    }

    /**
     * Verify: pin the host key if it is not yet, log in with the generated
     * key, and record what the server has installed.
     */
    public function check(Server $server): RedirectResponse
    {
        if (! $server->isVerified()) {
            $this->probe->captureHostKey($server);
        }

        if (! $server->isVerified()) {
            return back()->with('error', 'Could not read the host key: '.($server->last_error ?? 'unknown error'));
        }

        $result = $this->probe->check($server);

        if ($result->failed()) {
            return back()->with('error', 'Could not log in. Has the command been pasted on the server, as root? '.$result->failureReason());
        }

        $server->forceFill(['capabilities' => $this->probe->capabilities($server)])->save();
        SetupGuide::forget();

        return back()->with('success', 'Connected: '.$result->output());
    }

    /** Forget the pinned host key and learn it again. */
    public function rescan(Server $server): RedirectResponse
    {
        $server->forceFill(['host_key' => null])->save();

        $this->probe->captureHostKey($server);

        return $server->isVerified()
            ? back()->with('success', 'Host key re-captured.')
            : back()->with('error', 'Could not read the host key: '.($server->last_error ?? 'unknown error'));
    }

    /**
     * Replace the keypair, e.g. after a suspected leak. The old public key
     * stays in the server's authorized_keys until someone removes it; the new
     * one has to be pasted before the server can be reached again.
     */
    public function regenerateKey(Server $server): RedirectResponse
    {
        $keypair = SshKeypair::generate('control-plane@'.$server->hostname);

        $server->forceFill([
            'ssh_private_key' => $keypair->privateKey,
            'public_key' => $keypair->publicKey,
            'status' => 'pending',
        ])->save();

        return back()->with('success', 'New key generated. Paste the new command on the server, then click Verify.');
    }

    /** Set up server: queue the provisioning run and open its live log. */
    public function provision(Request $request, Server $server): RedirectResponse
    {
        if (! $server->isVerified() || $server->status !== 'online') {
            return back()->with('error', 'Verify the server first.');
        }

        // A second click while one is running opens the running one rather
        // than racing it.
        $running = $this->provisionTasks($server)->whereIn('status', ['queued', 'running'])->first();

        if ($running) {
            return redirect()->route('tasks.show', $running);
        }

        $task = Task::begin(Task::TYPE_PROVISION, "Set up {$server->name}", $server, $request->user());

        ProvisionServerJob::dispatch($server->id, $task->id);

        return redirect()->route('tasks.show', $task);
    }

    private function provisionTasks(Server $server)
    {
        return Task::query()
            ->where('type', Task::TYPE_PROVISION)
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Server $server): array
    {
        return [
            'id' => $server->id,
            'name' => $server->name,
            'hostname' => $server->hostname,
            'ip_address' => $server->ip_address,
            'ssh_user' => $server->ssh_user,
            'ssh_port' => $server->ssh_port,
            'status' => $server->status,
            'verified' => $server->isVerified(),
            'provisioned' => $server->isProvisioned(),
            // The fingerprint is safe to show and is what someone compares out
            // of band; the key itself never leaves the server row.
            'host_key_fingerprint' => $this->fingerprint($server),
            'last_seen_at' => $server->last_seen_at?->toIso8601String(),
        ];
    }

    private function fingerprint(Server $server): ?string
    {
        if (! $server->isVerified()) {
            return null;
        }

        $parts = preg_split('/\s+/', trim((string) $server->host_key));

        if (count($parts) < 3) {
            return null;
        }

        $decoded = base64_decode($parts[2], true);

        if ($decoded === false) {
            return null;
        }

        return $parts[1].' SHA256:'.rtrim(base64_encode(hash('sha256', $decoded, true)), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Server $server = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'ssh_user' => ['required', 'string', 'max:64'],
            'ssh_port' => [
                'required', 'integer', 'min:1', 'max:65535',
                Rule::unique('servers')->where(
                    fn ($query) => $query->where('hostname', $request->input('hostname'))
                )->ignore($server?->id),
            ],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
