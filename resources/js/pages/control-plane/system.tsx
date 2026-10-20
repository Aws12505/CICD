import { Head, Link, router } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import type { Tone } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

type Check = {
    key: string;
    title: string;
    status: 'ok' | 'warn' | 'fail';
    detail: string;
    fix: string | null;
};

const TONE: Record<Check['status'], Tone> = {
    ok: 'success',
    warn: 'warning',
    fail: 'danger',
};

const LABEL: Record<Check['status'], string> = {
    ok: 'working',
    warn: 'check this',
    fail: 'not working',
};

export default function System({
    checks,
    live,
}: {
    checks: Check[];
    live: boolean;
}) {
    const broken = checks.filter((c) => c.status !== 'ok');

    return (
        <>
            <Head title="System health" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow="Watch"
                    title="System health"
                    description="Whether the control plane itself works: the queue that runs every setup, build and release, the scheduler, and Reverb, which streams every log. Each problem comes with the command that fixes it."
                    actions={
                        <Button
                            variant={live ? 'outline' : 'default'}
                            onClick={() =>
                                router.get(
                                    '/control-plane/system',
                                    live ? {} : { live: 1 },
                                )
                            }
                        >
                            <Activity className="size-4" />{' '}
                            {live
                                ? 'Run the quick check'
                                : 'Test live logs end to end'}
                        </Button>
                    }
                    meta={
                        broken.length === 0 ? (
                            <StatusPill tone="success">
                                everything checked is working
                            </StatusPill>
                        ) : (
                            <StatusPill tone="danger">
                                {broken.length} to fix
                            </StatusPill>
                        )
                    }
                />

                <div className="grid gap-3">
                    {checks.map((check) => (
                        <Card key={check.key}>
                            <CardContent className="grid gap-3 p-5">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h2 className="font-medium">
                                        {check.title}
                                    </h2>
                                    <StatusPill tone={TONE[check.status]}>
                                        {LABEL[check.status]}
                                    </StatusPill>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {check.detail}
                                </p>
                                {check.fix && check.status !== 'ok' && (
                                    <div>
                                        <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Run on the control plane server
                                        </p>
                                        <pre className="overflow-x-auto rounded-lg bg-muted px-3 py-2 font-mono text-xs whitespace-pre-wrap">
                                            {check.fix}
                                        </pre>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <p className="text-sm text-muted-foreground">
                    Step by step, with the nginx lines:{' '}
                    <Link
                        href="/guide#live-logs"
                        className="text-primary underline"
                    >
                        Guide → Live logs and the queue worker
                    </Link>
                    .
                </p>
            </div>
        </>
    );
}

System.layout = {
    breadcrumbs: [{ title: 'System health', href: '/control-plane/system' }],
};
