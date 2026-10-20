import { Head, Link } from '@inertiajs/react';
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

function Steps({ items }: { items: [string, string][] }) {
    return (
        <ol className="grid gap-3">
            {items.map(([title, detail], i) => (
                <li key={title} className="flex gap-3 text-sm">
                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 font-mono text-xs text-primary">
                        {i + 1}
                    </span>
                    <div>
                        <span className="font-medium">{title}</span>{' '}
                        <span className="text-muted-foreground">{detail}</span>
                    </div>
                </li>
            ))}
        </ol>
    );
}

export default function Guide() {
    return (
        <>
            <Head title="Guide" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-semibold">
                        How this works
                    </h1>
                    <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                        One copy of the software, many clients, each on their
                        own server. Every difference between clients is a
                        setting here, never a copy of the code. Developers merge
                        into GitHub; that only builds. Nothing reaches a client
                        until you click.
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Onboard a new client</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Steps
                                items={[
                                    [
                                        'Add the server.',
                                        'Servers → Add. Paste the shown line into the VPS console as root, click Verify, then Set up server. Takes a few minutes and streams its log.',
                                    ],
                                    [
                                        'Add the client.',
                                        "Clients → Add: name, domain (e.g. acme.cloud), the server, the first admin's email.",
                                    ],
                                    [
                                        'Point DNS.',
                                        "At the client's registrar: *.acme.cloud → the server's IP. Certificates are issued automatically once this resolves.",
                                    ],
                                    [
                                        'Choose services.',
                                        'On the client page, tick the services for testing. Auth is always needed.',
                                    ],
                                    [
                                        'Fill the settings.',
                                        'Client → Environment: vendor credentials. Passwords, keys and URLs are generated for you. Red marks what is still missing.',
                                    ],
                                    [
                                        'Deploy testing.',
                                        'Client → Update, then Run. Check hiringtesting.acme.cloud and friends.',
                                    ],
                                    [
                                        'Promote to live.',
                                        'Tick the same services on live, then Client → Promote. Live gets exactly what testing ran.',
                                    ],
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
                                    [
                                        'A developer merges.',
                                        "Into the service's testing branch. GitHub tells the control plane; a build appears under Builds, numbered.",
                                    ],
                                    [
                                        'Look at Versions.',
                                        'Each client shows "n behind". Nothing has changed for them yet.',
                                    ],
                                    [
                                        "Update a client's testing.",
                                        'Versions → Update testing. Untick a service to hold it back, or pick an older build. Run.',
                                    ],
                                    [
                                        'Test, then promote.',
                                        'Versions → Promote. If a change drops or rewrites data, you are asked for an agreed time; the release then starts itself with a backup first.',
                                    ],
                                    [
                                        'Several clients at once.',
                                        'Tick them on Versions and use the buttons at the top. Pinned clients are skipped.',
                                    ],
                                    [
                                        'Something is wrong?',
                                        'Releases → open it → Roll back. Seconds, no data touched.',
                                    ],
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
                                    [
                                        'Add or remove a service.',
                                        'Client page → tick. Takes effect on their next update.',
                                    ],
                                    [
                                        'Change a credential or value.',
                                        'Client → Environment → Save → Apply. Restarts only what reads it. No build.',
                                    ],
                                    [
                                        'They need different behaviour.',
                                        'Developers make an edition branch named for the behaviour (edition/two-step-approval). Pick it for that service on the client page; only that client gets its builds.',
                                    ],
                                    [
                                        'Freeze them.',
                                        'Versions → Pin. Multi-client updates skip them until unpinned.',
                                    ],
                                    [
                                        'Get into their server.',
                                        'Servers → the server → Terminal, or Shell on a container. Every session is recorded.',
                                    ],
                                    [
                                        'Restore a backup.',
                                        'Client → Backups → Restore. Admins only; type the stack name; a fresh backup is taken first.',
                                    ],
                                ]}
                            />
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="border-b border-sidebar-border/70">
                        <CardTitle>What each page is for</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {AREAS.map((area) => (
                                <div
                                    key={area.href}
                                    className="grid gap-1 p-4 sm:grid-cols-[10rem_1fr]"
                                >
                                    <Link
                                        href={area.href}
                                        className="font-medium hover:underline"
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
