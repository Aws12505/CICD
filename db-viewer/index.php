<?php

/*
 * Adminer, signed in by the control plane.
 *
 * The browser arrives with a single-use ticket from the control plane. It is
 * redeemed for an SSH tunnel to one server's MySQL and the credentials of a
 * MySQL user whose rights match the person's role (read-only for operators),
 * kept in this session only. Adminer's own login form is never shown, and
 * whatever server a URL names, the connection goes to that tunnel and
 * nowhere else.
 */

declare(strict_types=1);
use Adminer\Adminer;

const SESSION_KEY = 'control_plane';

ini_set('session.save_path', '/tmp');
session_name('cp_db_viewer');
session_set_cookie_params([
    'path' => '/db-viewer/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function refuse(string $message): never
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Database</title>'
        .'<body style="font-family:system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:0 1rem">'
        .'<h1>Database</h1><p>'.htmlspecialchars($message).'</p></body>';
    exit;
}

/** Ask the control plane to swap a ticket for a tunnel and credentials. */
function redeem(string $ticket): ?array
{
    $url = rtrim((string) getenv('LARAVEL_URL'), '/').'/internal/db-viewer/redeem';
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-Gateway-Secret: ".getenv('TERMINAL_GATEWAY_SECRET')."\r\n",
        'content' => json_encode(['ticket' => $ticket]),
        'timeout' => 40,
        'ignore_errors' => true,
    ]]);

    $body = @file_get_contents($url, false, $context);
    $status = (int) (explode(' ', $http_response_header[0] ?? '')[1] ?? 0);

    return $status === 200 ? json_decode((string) $body, true) : null;
}

// Adminer's own assets (logo, CSS, JS) need no session.
$asset = isset($_GET['file']) && count($_GET) <= 2;

if (isset($_GET['ticket'])) {
    $access = redeem((string) $_GET['ticket']);

    if ($access === null) {
        refuse('This link has expired or was already used. Open the database again from the control plane.');
    }

    session_regenerate_id(true);
    $_SESSION[SESSION_KEY] = $access + ['opened_at' => time()];

    // Signed in, in Adminer's own session format, so its login form is never
    // involved; then off to the server's page, without the spent ticket.
    $server = $access['host'].':'.$access['port'];
    $_SESSION['pwds']['server'][$server][$access['username']] = $access['password'];
    $_SESSION['db']['server'][$server][$access['username']][''] = true;
    $_SESSION['token'] = random_int(1, 1000000);

    header('Location: ?server='.rawurlencode($server).'&username='.rawurlencode((string) $access['username']), true, 302);
    exit;
} elseif (! $asset && empty($_SESSION[SESSION_KEY])) {
    refuse('Open the database from the control plane: a client\'s stack, then "Database".');
}

function adminer_object()
{
    class ControlPlaneAdminer extends Adminer
    {
        private function access(): array
        {
            return $_SESSION[SESSION_KEY] ?? [];
        }

        public function name()
        {
            $access = $this->access();
            $mode = ($access['mode'] ?? '') === 'write' ? 'read and write' : 'read only';

            return htmlspecialchars(($access['server'] ?? 'Database').' · '.$mode);
        }

        /** Always the tunnel, whatever server the URL names. */
        public function credentials()
        {
            $access = $this->access();

            return [($access['host'] ?? '').':'.($access['port'] ?? ''), $access['username'] ?? '', $access['password'] ?? ''];
        }

        public function login($login, $password)
        {
            return $this->access() !== [];
        }

        public function loginForm()
        {
            echo '<p>Open the database from the control plane.</p>';
        }

        public function permanentLogin($create = false)
        {
            return '';
        }
    }

    return new ControlPlaneAdminer;
}

require __DIR__.'/adminer.php';
