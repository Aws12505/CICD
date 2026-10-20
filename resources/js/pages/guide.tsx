import { Head, Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/page-header';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Area = { title: string; href: string; what: string; when: string };

const AREAS: Area[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        what: 'Tells you the next thing to do and where each client stands.',
        when: 'Start here every time.',
    },
    {
        title: 'Settings',
        href: '/control-plane/settings',
        what: 'GitHub token, image registry tokens, webhook secret, certificate email.',
        when: 'Once, before anything else. Admins only.',
    },
    {
        title: 'Services',
        href: '/services',
        what: 'The catalog: one entry per service (Auth, Hiring, …) with its repository, the settings it needs, its worker processes and the commands a deploy runs.',
        when: 'Once, with "Add the standard services". Again when a service gains a setting or a new service is added.',
    },
    {
        title: 'Servers',
        href: '/servers',
        what: "Each client's VPS. Add → paste one line into the VPS console → Verify → Set up server. Afterwards: live CPU, memory and disk, containers, logs, a terminal.",
        when: 'When a new client gives you a server. Admins only.',
    },
    {
        title: 'Clients',
        href: '/clients',
        what: 'One entry per client with two stacks, testing and live. Tick which services they get. Their Environment page holds vendor credentials and any value that differs for them.',
        when: 'When onboarding a client, when adding a service to one, when a credential changes.',
    },
    {
        title: 'Versions',
        href: '/versions',
        what: "What every client runs: the build number on testing and live, and how many newer builds exist. Buttons to update a client's testing, promote it to live, or pin it.",
        when: 'Whenever you want a client to get something new. Nothing moves without a click here.',
    },
    {
        title: 'Builds',
        href: '/builds',
        what: 'Every image the control plane has built, numbered per service ("Hiring #143"), with the commits it contains. "Build now" for a branch.',
        when: 'Usually automatic from a merge. Open it to see what is new, or to build by hand.',
    },
    {
        title: 'Releases',
        href: '/releases',
        what: 'Every deploy, promotion, rollback and emergency, with its live log and a Roll back button.',
        when: 'After clicking Run on a plan, or to roll something back.',
    },
    {
        title: 'Operations',
        href: '/operations',
        what: 'Drift (a server running something other than what the control plane thinks) and backup health.',
        when: 'When the dashboard shows an alert.',
    },
    {
        title: 'Activity',
        href: '/tasks',
        what: 'The log of every long-running action: server setups, builds, releases, backups, restores.',
        when: 'To see what happened, or what is running right now.',
    },
];

type HowTo = {
    id: string;
    title: string;
    summary: string;
    steps: ReactNode[];
    note?: ReactNode;
};

const Code = ({ children }: { children: ReactNode }) => (
    <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em] text-foreground">
        {children}
    </code>
);

const HOW_TOS: HowTo[] = [
    {
        id: 'install',
        title: 'Install the control plane itself',
        summary:
            'A small Linux VPS that holds the keys to every client server.',
        steps: [
            <>
                Get a VPS for the control plane alone: Ubuntu 24.04, 2 vCPU, 4
                GB RAM, 40 GB disk, a public IPv4. Nothing else runs on it; it
                holds root access to every client.
            </>,
            <>
                Install Docker (
                <Code>curl -fsSL https://get.docker.com | sh</Code>
                ), clone the CICD repository and copy <Code>
                    .env.example
                </Code>{' '}
                to <Code>.env</Code>.
            </>,
            <>
                In <Code>.env</Code>: set <Code>APP_URL</Code> to the HTTPS
                address you will give it, the database, and generate{' '}
                <Code>APP_KEY</Code> once with{' '}
                <Code>php artisan key:generate</Code>. Back that key up off the
                machine: every stored secret is encrypted with it.
            </>,
            <>
                Set <Code>TERMINAL_GATEWAY_SECRET</Code> to 32+ random
                characters (<Code>openssl rand -hex 32</Code>) so the browser
                terminal works.
            </>,
            <>
                <Code>docker compose up -d --build</Code>, then{' '}
                <Code>php artisan migrate --force</Code> and{' '}
                <Code>php artisan db:seed --class=AdminUserSeeder</Code> for the
                first admin (its password comes from <Code>ADMIN_PASSWORD</Code>{' '}
                in <Code>.env</Code>).
            </>,
            <>
                Put nginx in front with a certificate, proxying <Code>/</Code>{' '}
                to the backend and <Code>/terminal/ws</Code> to the terminal
                gateway (the snippet is in{' '}
                <Code>terminal-gateway/README.md</Code>).
            </>,
            <>
                Restrict the control plane's own port 443 to your office
                addresses in the VPS firewall. Everything a client server
                accepts comes from this machine's IP.
            </>,
        ],
        note: 'The worker container runs the scheduler: metrics every minute, backups nightly, drift every five minutes. If it is down, the dashboard shows "No metrics".',
    },
    {
        id: 'live-logs',
        title: 'Live logs and the queue worker (when something stays "queued")',
        summary:
            'Reverb streams every log; the queue worker runs every setup, build and release. Both live in the worker container.',
        steps: [
            <>
                Open{' '}
                <Link href="/control-plane/system" className="underline">
                    System health
                </Link>
                . It says which of the queue, the scheduler and Reverb is not
                working and shows the command for it. "Test live logs end to
                end" publishes a real event.
            </>,
            <>
                <b>Reverb credentials.</b> The boot script generates{' '}
                <Code>REVERB_APP_ID</Code>, <Code>REVERB_APP_KEY</Code> and{' '}
                <Code>REVERB_APP_SECRET</Code> in <Code>.env</Code> when they
                are empty, rebuilds the frontend (the key is baked into it) and
                starts Reverb. After pulling an update:{' '}
                <Code>docker compose up -d --build</Code>.
            </>,
            <>
                <b>Same name everywhere.</b> <Code>APP_URL</Code> and{' '}
                <Code>REVERB_HOST</Code> must be the address you open the
                control plane at (<Code>REVERB_PORT=443</Code>,{' '}
                <Code>REVERB_SCHEME=https</Code>). If you change them, rebuild:{' '}
                <Code>docker exec CICD sh -c &apos;npm run build&apos;</Code>{' '}
                then <Code>docker compose restart</Code>.
            </>,
            <>
                <b>nginx must route two paths to Reverb</b>, not one:{' '}
                <Code>/app</Code> (the browser's websocket) and{' '}
                <Code>/apps</Code> (the server publishing events). Both proxy to{' '}
                <Code>127.0.0.1:42070</Code>. The full file is{' '}
                <Code>nginx/control-plane.conf.example</Code>; after editing,{' '}
                <Code>
                    sudo nginx -t &amp;&amp; sudo systemctl reload nginx
                </Code>
                .
            </>,
            <>
                <b>Check from the command line.</b> A working websocket answers{' '}
                <Code>101 Switching Protocols</Code>:{' '}
                <Code>
                    curl -i -N -H &quot;Connection: Upgrade&quot; -H
                    &quot;Upgrade: websocket&quot; -H
                    &quot;Sec-WebSocket-Version: 13&quot; -H
                    &quot;Sec-WebSocket-Key: x3JJHMbDL1EzLkh9GBhXDw==&quot;
                    &quot;https://YOUR-DOMAIN/app/YOUR_REVERB_APP_KEY?protocol=7&quot;
                </Code>
                .
            </>,
            <>
                <b>Stays "queued".</b> Nothing is taking jobs.{' '}
                <Code>docker exec CICD_Worker supervisorctl status</Code> must
                show <Code>laravel-worker_00</Code> and{' '}
                <Code>laravel-worker_01</Code> RUNNING. If one says FATAL or
                keeps restarting,{' '}
                <Code>
                    docker exec CICD_Worker tail -n 60
                    /var/log/supervisor/worker.log
                </Code>{' '}
                shows why. Then <Code>docker restart CICD_Worker</Code>.
            </>,
            <>
                <b>Jobs that failed</b> (the worker ran them and they crashed):{' '}
                <Code>docker exec CICD php artisan queue:failed</Code>, then{' '}
                <Code>docker exec CICD php artisan queue:retry all</Code> once
                fixed.
            </>,
        ],
        note: "A task that was already queued when you fix the worker starts by itself within a few seconds. Live log lines that were missed are still in the task's saved log: reload the page.",
    },
    {
        id: 'github-token',
        title: 'Get the GitHub token',
        summary:
            'Read access to the service repositories, so the control plane can fetch and build them.',
        steps: [
            <>
                On GitHub, open your profile picture → <b>Settings</b> →{' '}
                <b>Developer settings</b> → <b>Personal access tokens</b> →{' '}
                <b>Fine-grained tokens</b> → <b>Generate new token</b>.
            </>,
            <>
                Name it "control plane". Expiration: one year (put a reminder in
                the calendar). <b>Resource owner</b>: the organisation
                (RnD-Experts-Team), not your own account.
            </>,
            <>
                <b>Repository access</b>: "Only select repositories" and tick
                the eleven service repositories.
            </>,
            <>
                <b>Repository permissions</b>: Contents → Read-only; Metadata is
                added for you; Pull requests → Read and write (used to open a
                pull request when an edition cannot be merged cleanly).
            </>,
            <>
                Generate, copy the token once, and paste it into{' '}
                <Link href="/control-plane/settings" className="underline">
                    Settings → GitHub token
                </Link>
                .
            </>,
        ],
        note: 'If the organisation requires approval of fine-grained tokens, an organisation owner approves it under Organisation settings → Personal access tokens → Pending requests.',
    },
    {
        id: 'registry-tokens',
        title: 'Get the image registry tokens',
        summary:
            'Images are pushed to ghcr.io by the control plane and pulled by client servers.',
        steps: [
            <>
                Packages need a <b>classic</b> token: profile picture →{' '}
                <b>Settings</b> → <b>Developer settings</b> →{' '}
                <b>Personal access tokens</b> → <b>Tokens (classic)</b> →{' '}
                <b>Generate new token (classic)</b>.
            </>,
            <>
                First token, "control plane push": tick{' '}
                <Code>write:packages</Code> (this also includes{' '}
                <Code>read:packages</Code>). Paste it into{' '}
                <b>Settings → Registry push token</b>. It never leaves the
                control plane.
            </>,
            <>
                Second token, "client pull": tick only{' '}
                <Code>read:packages</Code>. Paste it into{' '}
                <b>Settings → Registry pull token</b>. This one is installed on
                every client server, which is why it must not be able to push.
            </>,
            <>
                <b>Registry username</b> is the GitHub username that owns these
                tokens. That account must be a member of the organisation with
                permission to create packages.
            </>,
            <>
                After the first build, the package appears under the
                organisation's Packages, private by default. That is correct:
                the pull token reads it.
            </>,
        ],
    },
    {
        id: 'webhook',
        title: 'Connect the GitHub webhook',
        summary:
            'So a merge builds an image on its own. One hook for the whole organisation is simplest.',
        steps: [
            <>
                Make a secret: <Code>openssl rand -hex 32</Code>, or any long
                random string. Paste it into{' '}
                <Link href="/control-plane/settings" className="underline">
                    Settings → Webhook secret
                </Link>{' '}
                and save. The Settings page then shows the <b>Payload URL</b>.
            </>,
            <>
                On GitHub: organisation page → <b>Settings</b> → <b>Webhooks</b>{' '}
                → <b>Add webhook</b> (one hook covers every repository; or do
                the same per repository under the repository's Settings →
                Webhooks).
            </>,
            <>
                Payload URL: the one from Settings. Content type:{' '}
                <Code>application/json</Code>. Secret: the one you made. "Just
                the push event". Active. Add.
            </>,
            <>
                Check: under the webhook's <b>Recent Deliveries</b>, the ping
                should show a green 200. Push to a service's testing branch and
                a build appears under{' '}
                <Link href="/builds" className="underline">
                    Builds
                </Link>{' '}
                within a minute.
            </>,
        ],
        note: 'A push to any other branch is ignored on purpose. Only the build branch (usually testing) and edition/* branches build.',
    },
    {
        id: 'services',
        title: 'Fill the service catalog',
        summary: 'One click; then check two things.',
        steps: [
            <>
                <Link href="/services" className="underline">
                    Services
                </Link>{' '}
                → <b>Add the standard services</b>. The eleven services appear
                with their settings, streams and worker processes.
            </>,
            <>
                Open <b>Auth (pizzasys)</b> and <b>QA (AuditApp)</b>: they build
                from <Code>events_testing</Code> and record on{' '}
                <Code>events</Code>, because those repositories name their
                branches that way. Every other service builds from{' '}
                <Code>testing</Code>.
            </>,
            <>
                Open <b>Dashboard</b>: its subdomain is <Code>app</Code> by
                default, giving <Code>app.client-domain</Code>. Change it on the
                service if you want another word.
            </>,
        ],
    },
    {
        id: 'server',
        title: 'Add and set up a client server',
        summary:
            'From a bare VPS to one that can host stacks, in three clicks.',
        steps: [
            <>
                Ask for (or create) a VPS: Ubuntu 24.04 LTS (22.04 or Debian 12
                also work), 4 vCPU, 8 GB RAM, 80 GB SSD or more, a public IPv4,
                and access to the provider's <b>web console</b> as root. Nothing
                needs to be installed by hand.
            </>,
            <>
                <Link href="/servers/create" className="underline">
                    Servers → Add server
                </Link>
                : a name, the server's <b>IP address</b> in "Address to connect
                to" (not a domain: SSH needs no DNS, and a domain that points
                somewhere else would reach the wrong machine), user{' '}
                <Code>root</Code>, port 22.
            </>,
            <>
                On the server page, copy the line under step 1. Open the
                provider's web console (Hetzner: Console; DigitalOcean: Access →
                Launch Console; Contabo: VNC), log in as root, paste it, press
                Enter. It only adds the control plane's public key.
            </>,
            <>
                Click <b>Verify</b>. It pins the server's host key, logs in and
                lists what is installed. "Could not log in" means the line was
                not pasted as root.
            </>,
            <>
                Click <b>Set up server</b>. Docker, nginx, certbot and the
                firewall are installed, then the shared MySQL, Redis and NATS
                start. Watch the log; it takes a few minutes. Safe to run again.
            </>,
        ],
        note: 'After setup, SSH on that server is allowed only from the control plane. The provider console still works if ever needed.',
    },
    {
        id: 'dns',
        title: 'Point the client domain',
        summary: 'One wildcard record; certificates follow on their own.',
        steps: [
            <>
                Decide the client's domain, e.g. <Code>acme.cloud</Code>. Every
                service lives under it: <Code>hiring.acme.cloud</Code> live,{' '}
                <Code>hiringtesting.acme.cloud</Code> testing, and so on.
            </>,
            <>
                At the registrar or DNS host, add an <b>A record</b> with name{' '}
                <Code>*</Code> pointing at the server's IP (and <Code>@</Code>{' '}
                too if the bare domain should resolve). TTL 300.
            </>,
            <>
                Check with <Code>nslookup hiringtesting.acme.cloud</Code> from
                any machine; it should answer with the server's IP. DNS can take
                up to an hour to settle.
            </>,
            <>
                Certificates are requested on each deploy for every hostname
                that resolves, and renewed automatically. A hostname that does
                not resolve yet stays on plain HTTP and is retried next time.
            </>,
        ],
    },
    {
        id: 'client',
        title: 'Add a client and choose its services',
        summary:
            'Two stacks, a tick per service, an edition only when they need different code.',
        steps: [
            <>
                <Link href="/clients/create" className="underline">
                    Clients → Add client
                </Link>
                : name, a short slug (lowercase, letters, digits and dashes; it
                becomes database and container names), the domain, the server,
                and the first admin's email and name.
            </>,
            <>
                Both stacks are created. On the client page, tick the services
                for <b>testing</b> first. Auth (pizzasys) is always needed:
                every other service checks users against it.
            </>,
            <>
                Leave <b>edition</b> empty. Only when developers have made an{' '}
                <Code>edition/…</Code> branch for behaviour this client needs do
                you pick it, and only for that service.
            </>,
            <>
                Later, tick the same services on <b>live</b>, before the first
                promotion.
            </>,
        ],
    },
    {
        id: 'environment',
        title: "Fill in a client's settings",
        summary:
            'Vendor credentials and anything that differs for this client. Everything else is generated.',
        steps: [
            <>
                Client page → <b>Environment</b>. The two cards at the top say
                what is still missing per tier; the release will not start while
                anything required is missing.
            </>,
            <>
                <b>Both tiers</b> is where most values go: a Humanity or TCP
                login, LiveKit keys for Screens, mail settings. Secrets show
                "saved" and are never displayed again; leave the field empty to
                keep one.
            </>,
            <>
                <b>Testing only</b> / <b>Live only</b> override a value on one
                tier, e.g. <Code>TCP_DRIVER=fake</Code> on testing and{' '}
                <Code>tcp</Code> on live.
            </>,
            <>
                Passwords, APP_KEYs, service tokens, database names, URLs and
                NATS settings are worked out by the control plane. Open "values
                worked out by the control plane" under a service to see them.
            </>,
            <>
                Save, then <b>Apply</b> on a tier that is already running:
                containers that read the changed values are recreated. No build.
            </>,
        ],
    },
    {
        id: 'deploy',
        title: 'Deploy testing, then promote to live',
        summary:
            'The same two clicks for the first deploy and for every update after.',
        steps: [
            <>
                Make sure every enabled service has a build (
                <Link href="/builds" className="underline">
                    Builds
                </Link>{' '}
                → Build now if a merge has not happened yet).
            </>,
            <>
                Client page → <b>Update</b> on the testing stack. Each service
                shows what it runs and the newest build; untick to hold one back
                or pick an older build. <b>Run</b>. The log streams on the
                release page.
            </>,
            <>
                The first deploy also creates the databases, registers every
                service with Auth, creates the first admin (password: client
                page → Show passwords, admins only) and requests certificates.
            </>,
            <>
                Test on the <Code>…testing.</Code> hostnames. Then client page →{' '}
                <b>Promote</b> on the live stack → Run. Live gets exactly the
                images testing ran.
            </>,
            <>
                A build that drops or rewrites data asks for an agreed time
                instead of running: a maintenance page goes up, the affected
                tables are backed up, and it starts itself at that time.
            </>,
        ],
        note: 'Emergency (straight to live, typed reason) lives behind the Promote screen. Roll back from any finished release in seconds.',
    },
    {
        id: 'several',
        title: 'Update several clients at once',
        summary: 'Still a click, never automatic.',
        steps: [
            <>
                <Link href="/versions" className="underline">
                    Versions
                </Link>{' '}
                shows every client × service with "n behind". Tick the clients.
            </>,
            <>
                <b>Update testing</b> or <b>Promote to live</b> at the top.
                Pinned clients and anything that needs a maintenance window are
                skipped and listed in the message; plan those from the client.
            </>,
            <>
                <b>Pin</b> a client to keep them out of these until unpinned.
            </>,
        ],
    },
    {
        id: 'server-ops',
        title: 'Look inside a server',
        summary: 'Metrics, logs, restarts and a real terminal, all recorded.',
        steps: [
            <>
                Server page: live CPU, memory, disk and load refreshed every
                five seconds, 24-hour charts, and every container with the build
                it runs.
            </>,
            <>
                <b>Logs</b> on a container shows its last lines; <b>Restart</b>{' '}
                restarts it; <b>Shell</b> opens a terminal inside it.
            </>,
            <>
                <b>Terminal</b> at the top opens a root shell on the server in
                the browser. Every session is recorded and listed under{' '}
                <b>Recordings</b>, replayable.
            </>,
            <>
                <b>Quick actions</b>: test and reload nginx, renew certificates,
                prune old images, restart the shared MySQL/Redis/NATS. Each
                streams to Activity.
            </>,
        ],
    },
    {
        id: 'backups',
        title: 'Back up and restore',
        summary:
            'Databases nightly, files weekly, pulled to the control plane. Restoring is a decision.',
        steps: [
            <>
                Client page → <b>Backups</b> on a stack: every backup, its size
                and whether it succeeded. <b>Back up databases now</b> /{' '}
                <b>files now</b> for an immediate one.
            </>,
            <>
                A stack with no successful backup in a day shows an alert on the
                dashboard.
            </>,
            <>
                <b>Restore</b> (admins): type the stack's name and a reason. A
                fresh backup of the current state is taken first, then the
                chosen backup is loaded. Everything written since it is lost,
                which is why it asks.
            </>,
        ],
    },
    {
        id: 'users',
        title: 'Give someone access to the control plane',
        summary: 'Three roles, no self-registration.',
        steps: [
            <>
                <b>Admin</b>: everything, including servers, settings, terminal
                and restores. <b>Operator</b>: clients, services, builds and
                releases, but not servers or settings. <b>Viewer</b>: read only.
            </>,
            <>
                Registration is switched off. An admin creates accounts on the
                control plane server with <Code>php artisan tinker</Code>:{' '}
                <Code>
                    User::create([...])-&gt;assignRole(&apos;operator&apos;)
                </Code>
                . A Users page is on the list.
            </>,
        ],
    },
];

function Steps({ items }: { items: ReactNode[] }) {
    return (
        <ol className="grid gap-3">
            {items.map((item, i) => (
                <li key={i} className="flex gap-3 text-sm leading-relaxed">
                    <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 font-mono text-xs text-primary">
                        {i + 1}
                    </span>
                    <div className="min-w-0">{item}</div>
                </li>
            ))}
        </ol>
    );
}

function HowToSection({ howTo }: { howTo: HowTo }) {
    return (
        <details
            id={howTo.id}
            open={
                typeof window !== 'undefined' &&
                window.location.hash === `#${howTo.id}`
            }
            className="group rounded-xl border border-border bg-card shadow-card"
        >
            <summary className="flex min-h-14 items-center justify-between gap-3 px-5 py-3">
                <div>
                    <div className="font-medium">{howTo.title}</div>
                    <div className="text-sm text-muted-foreground">
                        {howTo.summary}
                    </div>
                </div>
                <ChevronDown className="size-5 shrink-0 text-muted-foreground transition-transform group-open:rotate-180" />
            </summary>
            <div className="border-t border-border px-5 py-4">
                <Steps items={howTo.steps} />
                {howTo.note && (
                    <p className="mt-4 rounded-lg bg-muted/60 px-3 py-2 text-sm text-muted-foreground">
                        {howTo.note}
                    </p>
                )}
            </div>
        </details>
    );
}

export default function Guide() {
    return (
        <>
            <Head title="Guide" />
            <div className="flex h-full flex-1 flex-col gap-8 p-4 md:p-6">
                <PageHeader
                    eyebrow="Guide"
                    title="How this works"
                    description="One copy of the software, many clients, each on their own server. Every difference between clients is a setting here, never a copy of the code. Developers merge into GitHub; that only builds. Nothing reaches a client until you click."
                />

                <section className="grid gap-4">
                    <h2 className="font-display text-xl font-semibold">
                        The three things you will do
                    </h2>
                    <div className="grid gap-4 lg:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle>Onboard a new client</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Steps
                                    items={[
                                        <>
                                            <b>Add the server.</b> Servers →
                                            Add, paste the line into the VPS
                                            console, Verify, Set up server.
                                        </>,
                                        <>
                                            <b>Add the client.</b> Name, domain,
                                            server, first admin.
                                        </>,
                                        <>
                                            <b>Point DNS.</b>{' '}
                                            <Code>*.domain</Code> → the server's
                                            IP.
                                        </>,
                                        <>
                                            <b>Choose services</b> on testing.
                                        </>,
                                        <>
                                            <b>Fill the settings.</b> Red shows
                                            what is missing.
                                        </>,
                                        <>
                                            <b>Deploy testing</b>, check it.
                                        </>,
                                        <>
                                            <b>Promote to live.</b>
                                        </>,
                                    ]}
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Ship an update</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Steps
                                    items={[
                                        <>
                                            <b>A developer merges</b> into
                                            testing. A numbered build appears.
                                        </>,
                                        <>
                                            <b>Versions</b> shows who is behind.
                                        </>,
                                        <>
                                            <b>Update testing</b> for a client.
                                            Hold back or pick older per service.
                                        </>,
                                        <>
                                            <b>Test, then Promote.</b> Data
                                            changes wait for an agreed time.
                                        </>,
                                        <>
                                            <b>Something wrong?</b> Releases →
                                            Roll back.
                                        </>,
                                    ]}
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Change one client</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Steps
                                    items={[
                                        <>
                                            <b>Add or remove a service:</b> tick
                                            on the client page.
                                        </>,
                                        <>
                                            <b>Change a credential:</b>{' '}
                                            Environment → Save → Apply.
                                        </>,
                                        <>
                                            <b>Different behaviour:</b> pick
                                            their edition for that service.
                                        </>,
                                        <>
                                            <b>Freeze them:</b> Pin on Versions.
                                        </>,
                                        <>
                                            <b>Get in:</b> Servers → Terminal.
                                        </>,
                                    ]}
                                />
                            </CardContent>
                        </Card>
                    </div>
                </section>

                <section className="grid gap-4">
                    <div>
                        <h2 className="font-display text-xl font-semibold">
                            Step by step
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Exactly where to click, on GitHub, at the registrar
                            and here.
                        </p>
                    </div>
                    <div className="grid gap-3">
                        {HOW_TOS.map((howTo) => (
                            <HowToSection key={howTo.id} howTo={howTo} />
                        ))}
                    </div>
                </section>

                <Card>
                    <CardHeader className="border-b border-border">
                        <CardTitle>What each page is for</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y divide-border">
                            {AREAS.map((area) => (
                                <div
                                    key={area.href}
                                    className="grid gap-1 p-4 sm:grid-cols-[10rem_1fr]"
                                >
                                    <Link
                                        href={area.href}
                                        className="font-medium text-primary hover:underline"
                                    >
                                        {area.title}
                                    </Link>
                                    <div className="text-sm">
                                        <p>{area.what}</p>
                                        <p className="mt-0.5 text-muted-foreground">
                                            {area.when}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Words you will see</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-sm sm:grid-cols-2">
                        {(
                            [
                                [
                                    'Stack',
                                    "One client's testing or live: its containers, databases and domains on a server.",
                                ],
                                [
                                    'Build',
                                    'One image of one service from one commit. "Hiring #143" is the 143rd build of Hiring.',
                                ],
                                [
                                    'Release',
                                    'Moving a stack to chosen builds. Testing updates, promotions, rollbacks and emergencies are all releases.',
                                ],
                                [
                                    'Promote',
                                    'Copy exactly what testing runs onto live. No rebuild, so live gets the bytes testing proved.',
                                ],
                                [
                                    'Edition',
                                    'A branch for a client who needs different code, named for the behaviour, never the client. Shared by every client who wants it.',
                                ],
                                [
                                    'Drift',
                                    'A server running something other than what the control plane last deployed. Someone changed it by hand.',
                                ],
                                [
                                    'Pin',
                                    'Leave a client out of multi-client updates.',
                                ],
                                [
                                    'Apply',
                                    'Put saved settings into effect by restarting the containers that read them.',
                                ],
                            ] as [string, string][]
                        ).map(([term, meaning]) => (
                            <div key={term}>
                                <span className="font-medium">{term}</span>{' '}
                                <span className="text-muted-foreground">
                                    {meaning}
                                </span>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Guide.layout = {
    breadcrumbs: [{ title: 'Guide', href: '/guide' }],
};
