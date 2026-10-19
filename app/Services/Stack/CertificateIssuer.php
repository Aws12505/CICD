<?php

namespace App\Services\Stack;

use App\Contracts\Target;
use App\Models\Setting;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Remote\CommandRunner;
use App\Support\Shell;
use Closure;

/**
 * A Let's Encrypt certificate for every hostname a stack serves.
 *
 * Issued with `certbot certonly --webroot` through the plain vhost each
 * hostname starts on, then the TLS vhost is linked in its place. Renewal is
 * certbot's own timer, through the same webroot, with the reload hook
 * provisioning installed.
 *
 * A hostname that cannot be issued — almost always DNS not pointing here yet
 * — is reported, not fatal: the service stays reachable over plain HTTP and
 * the next release tries again.
 */
class CertificateIssuer
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly StackSync $sync,
    ) {}

    /**
     * @param  Closure(string):void  $log
     * @return list<string> hostnames still without a certificate
     */
    public function ensure(Stack $stack, Target $target, Closure $log): array
    {
        $stack->loadMissing('client');
        $email = Setting::get(Setting::ACME_EMAIL);
        $missing = [];

        // Link what exists first, so each hostname's challenge can be served.
        $this->sync->reloadNginx($stack, $target);

        foreach ($this->domains($stack) as $domain) {
            $quoted = Shell::quote($domain);

            $command = 'if [ -f /etc/letsencrypt/live/'.$quoted.'/fullchain.pem ]; then echo '.Shell::quote("{$domain}: certificate present").'; else '
                .'certbot certonly --webroot -w /var/www/acme -d '.$quoted
                .' --non-interactive --agree-tos --keep-until-expiring '
                .($email ? '-m '.Shell::quote($email) : '--register-unsafely-without-email')
                .'; fi';

            $result = $this->runner->run($target, $command, timeout: 180, onOutput: $log);

            if ($result->failed()) {
                $missing[] = $domain;
                $log("No certificate for {$domain} yet. Check that its DNS points at this server; it stays on plain HTTP until then.\n");
            }
        }

        // Swap in the TLS vhosts for everything that now has a certificate.
        $reload = $this->sync->reloadNginx($stack, $target);

        if ($reload->failed()) {
            $log('nginx did not reload: '.$reload->failureReason()."\n");
        }

        return $missing;
    }

    /** @return list<string> */
    public function domains(Stack $stack): array
    {
        return StackService::query()
            ->with('service')
            ->where('stack_id', $stack->id)
            ->where('enabled', true)
            ->get()
            ->map(fn (StackService $s) => $stack->domainFor($s->service))
            ->values()
            ->all();
    }
}
