<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Remote\ServerProbe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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

        $server = Server::create($data + ['status' => 'pending']);

        // Learn the host key immediately so the very next connection can pin
        // it. A server whose key was never captured is one every later
        // connection would have to trust blindly.
        $this->probe->captureHostKey($server);

        if ($server->isVerified()) {
            $this->probe->check($server);
        }

        return redirect()
            ->route('servers.show', $server)
            ->with('success', 'Server added.');
    }

    public function show(Server $server): Response
    {
        return Inertia::render('servers/show', [
            'server' => $this->summarise($server) + [
                'notes' => $server->notes,
                'last_error' => $server->last_error,
            ],
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

        // Leaving the key field blank keeps the existing one, so editing a
        // hostname does not force someone to paste a private key again.
        if (blank($data['ssh_private_key'] ?? null)) {
            unset($data['ssh_private_key']);
        }

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
        $server->delete();

        return redirect()->route('servers.index')->with('success', 'Server removed.');
    }

    /** Re-run the connection check on demand. */
    public function check(Server $server): RedirectResponse
    {
        if (! $server->isVerified()) {
            $this->probe->captureHostKey($server);
        }

        if (! $server->isVerified()) {
            return back()->with('error', 'Could not read the host key: '.($server->last_error ?? 'unknown error'));
        }

        $result = $this->probe->check($server);

        return $result->successful()
            ? back()->with('success', 'Connected: '.$result->output())
            : back()->with('error', 'Connection failed: '.$result->failureReason());
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
            'ssh_private_key' => [$server ? 'nullable' : 'required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
