<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\TerminalSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The browser terminal (decision 21): admin-only, every session recorded.
 *
 * Laravel issues a single-use token; the gateway redeems it for the server's
 * connection details through an internal endpoint guarded by a shared
 * secret, holds the SSH session, and hands the transcript back at the end.
 */
class TerminalController extends Controller
{
    public function show(Request $request, Server $server): Response
    {
        $container = $request->string('container')->toString() ?: null;

        return Inertia::render('servers/terminal', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'hostname' => $server->hostname],
            'container' => $container,
            'enabled' => filled(config('terminal.gateway_secret')),
        ]);
    }

    /** A token for one connection, good for a minute. */
    public function issue(Request $request, Server $server): JsonResponse
    {
        abort_unless(filled(config('terminal.gateway_secret')), 503, 'The terminal gateway is not configured.');
        abort_unless($server->isVerified(), 409, 'Verify the server first.');

        $container = $request->string('container')->toString() ?: null;

        if ($container !== null) {
            $known = collect(ServerMetric::query()->where('server_id', $server->id)->latest('recorded_at')->first()?->containers ?? [])->pluck('name');
            abort_unless((bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $container) && $known->contains($container), 404);
        }

        [$session, $token] = TerminalSession::issue($server, $request->user(), $container, $request->ip());

        return response()->json([
            'session_id' => $session->id,
            'token' => $token,
            'url' => config('terminal.websocket_url'),
        ]);
    }

    public function sessions(Server $server): Response
    {
        return Inertia::render('servers/terminal-sessions', [
            'server' => ['id' => $server->id, 'name' => $server->name],
            'sessions' => TerminalSession::query()
                ->with('user')
                ->where('server_id', $server->id)
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn (TerminalSession $s) => [
                    'id' => $s->id,
                    'user' => $s->user?->name,
                    'container' => $s->container,
                    'started_at' => $s->started_at?->toIso8601String(),
                    'ended_at' => $s->ended_at?->toIso8601String(),
                    'end_reason' => $s->end_reason,
                    'client_ip' => $s->client_ip,
                    'recording' => $s->recording_path !== null,
                    'recording_bytes' => $s->recording_bytes,
                    'truncated' => $s->recording_truncated,
                ]),
        ]);
    }

    /** The asciicast transcript, for the replay page. */
    public function recording(Server $server, TerminalSession $session): StreamedResponse
    {
        abort_unless($session->server_id === $server->id && $session->recording_path, 404);

        return Storage::disk('local')->download($session->recording_path, "terminal-{$session->id}.cast", [
            'Content-Type' => 'application/x-asciicast',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internal: called by the gateway only
    |--------------------------------------------------------------------------
    */

    /**
     * Swap a token for connection details. One use, one minute, and only
     * for a caller holding the gateway secret.
     */
    public function redeem(Request $request): JsonResponse
    {
        $this->assertGateway($request);

        $hash = hash('sha256', (string) $request->input('token'));

        $session = DB::transaction(function () use ($hash) {
            $session = TerminalSession::query()->where('token_hash', $hash)->lockForUpdate()->first();

            if ($session === null || $session->started_at !== null || $session->token_expires_at->isPast()) {
                return null;
            }

            $session->update(['started_at' => now()]);

            return $session;
        });

        abort_if($session === null, 404);

        $server = $session->server;

        return response()->json([
            'session_id' => $session->id,
            'host' => $server->hostname,
            'port' => $server->ssh_port,
            'user' => $server->ssh_user,
            'private_key' => $server->ssh_private_key,
            'host_key' => $server->host_key,
            'container' => $session->container,
            'cols' => 120,
            'rows' => 32,
        ]);
    }

    /** Store the transcript when the session ends. */
    public function finish(Request $request): JsonResponse
    {
        $this->assertGateway($request);

        $session = TerminalSession::query()->whereNotNull('started_at')->findOrFail((int) $request->input('session_id'));

        if ($session->ended_at !== null) {
            return response()->json(['ok' => true]);
        }

        // Base64 from the gateway, so request trimming cannot touch it.
        $recording = (string) base64_decode((string) $request->input('recording_b64', ''), true);
        $path = null;

        if ($recording !== '') {
            $path = 'terminal/'.$session->server_id.'/'.$session->id.'.cast';
            Storage::disk('local')->put($path, $recording);
        }

        $session->update([
            'ended_at' => now(),
            'end_reason' => substr((string) $request->input('reason', 'ended'), 0, 255),
            'recording_path' => $path,
            'recording_bytes' => strlen($recording),
            'recording_truncated' => $request->boolean('truncated'),
        ]);

        return response()->json(['ok' => true]);
    }

    private function assertGateway(Request $request): void
    {
        $secret = (string) config('terminal.gateway_secret');

        // The same 404 whether the terminal is off or the secret is wrong.
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Gateway-Secret')), 404);
    }
}
