<?php

namespace App\Http\Controllers;

use App\Models\DbViewerSession;
use App\Models\Stack;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A server's MySQL in the browser, through Adminer.
 *
 * Nothing about MySQL is exposed on the client server. The browser gets a
 * single-use ticket; the db-viewer container (Adminer) redeems it here; this
 * asks the terminal gateway for an SSH tunnel to that server's MySQL and
 * hands back the tunnel and the credentials of a MySQL user matching the
 * person's role: admins can change data, operators can only read it.
 */
class DbViewerController extends Controller
{
    /**
     * Open the viewer on the server a stack runs on. From a stack, because
     * operators reach clients and stacks but never the server pages.
     */
    public function open(Request $request, Stack $stack): RedirectResponse
    {
        $server = $stack->server;

        if (blank(config('terminal.gateway_secret'))) {
            return back()->with('error', 'The database viewer uses the terminal gateway, which is switched off: set TERMINAL_GATEWAY_SECRET.');
        }

        if ($server === null || ! $server->isProvisioned()) {
            return back()->with('error', 'Set up the server first: its MySQL does not exist yet.');
        }

        $mode = $request->user()->hasRole(Roles::ADMIN) ? DbViewerSession::MODE_WRITE : DbViewerSession::MODE_READ;
        [, $ticket] = DbViewerSession::issue($server, $request->user(), $mode, $request->ip());

        return redirect()->away('/db-viewer/?ticket='.$ticket);
    }

    /*
    |--------------------------------------------------------------------------
    | Internal: called by the db-viewer container only
    |--------------------------------------------------------------------------
    */

    /**
     * Swap a ticket for a tunnel and credentials. One use, one minute.
     */
    public function redeem(Request $request): JsonResponse
    {
        $this->assertInternal($request);

        $hash = hash('sha256', (string) $request->input('ticket'));

        $session = DB::transaction(function () use ($hash) {
            $session = DbViewerSession::query()->where('ticket_hash', $hash)->lockForUpdate()->first();

            if ($session === null || $session->started_at !== null || $session->ticket_expires_at->isPast()) {
                return null;
            }

            $session->update(['started_at' => now()]);

            return $session;
        });

        abort_if($session === null, 404);

        $server = $session->server;

        try {
            $tunnel = Http::timeout(30)
                ->withHeaders(['X-Gateway-Secret' => (string) config('terminal.gateway_secret')])
                ->post(rtrim((string) config('terminal.gateway_url'), '/').'/internal/tunnels', [
                    'session_id' => $session->id,
                    'host' => $server->hostname,
                    'port' => $server->ssh_port,
                    'user' => $server->ssh_user,
                    'private_key' => $server->ssh_private_key,
                    'host_key' => $server->host_key,
                ])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            $session->update(['ended_at' => now(), 'end_reason' => 'tunnel failed: '.substr($e->getMessage(), 0, 200)]);

            return response()->json(['error' => 'Could not reach the server\'s MySQL through SSH.'], 502);
        }

        return response()->json([
            'session_id' => $session->id,
            'server' => $server->name,
            'mode' => $session->mode,
            'host' => (string) parse_url((string) config('terminal.gateway_url'), PHP_URL_HOST),
            'port' => (int) $tunnel['port'],
            'username' => $session->mysqlUser(),
            'password' => $server->secret('mysql_viewer_'.($session->mode === DbViewerSession::MODE_WRITE ? 'rw' : 'ro')),
        ]);
    }

    /** The gateway closed the tunnel (idle, too long, or the server went away). */
    public function finish(Request $request): JsonResponse
    {
        $this->assertInternal($request);

        $session = DbViewerSession::query()->whereNotNull('started_at')->findOrFail((int) $request->input('session_id'));

        if ($session->ended_at === null) {
            $session->update([
                'ended_at' => now(),
                'end_reason' => substr((string) $request->input('reason', 'ended'), 0, 255),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function assertInternal(Request $request): void
    {
        $secret = (string) config('terminal.gateway_secret');

        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Gateway-Secret')), 404);
    }
}
