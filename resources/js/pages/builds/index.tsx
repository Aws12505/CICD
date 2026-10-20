import { Head, Link, router, useForm } from '@inertiajs/react';
import { Hammer } from 'lucide-react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAbilities } from '@/hooks/use-abilities';

type BuildRow = {
    id: number;
    label: string;
    service: string;
    service_slug: string;
    number: number | null;
    edition: string | null;
    stack_id: number | null;
    branch: string;
    sha: string | null;
    status: 'pending' | 'running' | 'success' | 'failed';
    trigger: string;
    user: string | null;
    migration_kind: 'none' | 'additive' | 'destructive';
    commits: number;
    changelog: { sha: string; author: string; subject: string }[];
    error: string | null;
    task_id: number | null;
    built_at: string | null;
    created_at: string | null;
};

type ServiceOption = {
    id: number;
    name: string;
    slug: string;
    default_branch: string;
    per_stack_build: boolean;
};

const STATUS: Record<
    BuildRow['status'],
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'outline',
    running: 'default',
    success: 'secondary',
    failed: 'destructive',
};

export default function BuildsIndex({
    builds,
    services,
    filter,
}: {
    builds: BuildRow[];
    services: ServiceOption[];
    filter: string | null;
}) {
    const { manage: canManage } = useAbilities();

    const form = useForm({
        service_id: services[0] ? String(services[0].id) : '',
        branch: services[0]?.default_branch ?? 'testing',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/builds');
    };

    return (
        <>
            <Head title="Builds" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <PageHeader
                    eyebrow="Ship"
                    title="Builds"
                    description="A merge into a service's build branch (or an edition/… branch) builds an image here, numbered per service. Nothing is deployed until you update a client from Versions."
                />

                {canManage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Build now</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={submit}
                                className="flex flex-wrap items-end gap-4"
                            >
                                <div className="grid gap-2">
                                    <Label>Service</Label>
                                    <Select
                                        value={form.data.service_id}
                                        onValueChange={(v) => {
                                            const service = services.find(
                                                (s) => String(s.id) === v,
                                            );
                                            form.setData({
                                                service_id: v,
                                                branch:
                                                    service?.default_branch ??
                                                    form.data.branch,
                                            });
                                        }}
                                    >
                                        <SelectTrigger className="w-56">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {services.map((s) => (
                                                <SelectItem
                                                    key={s.id}
                                                    value={String(s.id)}
                                                >
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="branch">Branch</Label>
                                    <Input
                                        id="branch"
                                        className="w-64 font-mono"
                                        value={form.data.branch}
                                        onChange={(e) =>
                                            form.setData(
                                                'branch',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing || !form.data.service_id
                                    }
                                >
                                    <Hammer className="size-4" /> Build
                                </Button>
                            </form>
                            <InputError
                                className="mt-2"
                                message={form.errors.branch}
                            />
                        </CardContent>
                    </Card>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant={filter ? 'outline' : 'default'}
                        onClick={() => router.get('/builds')}
                    >
                        All
                    </Button>
                    {services.map((s) => (
                        <Button
                            key={s.id}
                            size="sm"
                            variant={filter === s.slug ? 'default' : 'outline'}
                            onClick={() =>
                                router.get('/builds', { service: s.slug })
                            }
                        >
                            {s.name}
                        </Button>
                    ))}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {builds.length === 0 && (
                                <p className="p-4 text-sm text-muted-foreground">
                                    No builds yet.
                                </p>
                            )}
                            {builds.map((build) => (
                                <details key={build.id} className="group">
                                    <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-3 p-4">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2 font-medium">
                                                {build.label}
                                                {build.stack_id && (
                                                    <Badge variant="outline">
                                                        stack {build.stack_id}
                                                    </Badge>
                                                )}
                                                {build.migration_kind !==
                                                    'none' && (
                                                    <Badge
                                                        variant={
                                                            build.migration_kind ===
                                                            'destructive'
                                                                ? 'destructive'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {build.migration_kind}{' '}
                                                        migration
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-1 font-mono text-xs text-muted-foreground">
                                                {build.branch} · {build.commits}{' '}
                                                commits · {build.trigger}
                                                {build.user
                                                    ? ` by ${build.user}`
                                                    : ''}{' '}
                                                ·{' '}
                                                {new Date(
                                                    build.built_at ??
                                                        build.created_at ??
                                                        '',
                                                ).toLocaleString()}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant={STATUS[build.status]}
                                            >
                                                {build.status}
                                            </Badge>
                                            {build.task_id && (
                                                <Link
                                                    href={`/tasks/${build.task_id}`}
                                                    className="text-sm underline-offset-4 hover:underline"
                                                >
                                                    Log
                                                </Link>
                                            )}
                                        </div>
                                    </summary>
                                    <div className="grid gap-1 px-4 pb-4">
                                        {build.error && (
                                            <p className="font-mono text-xs text-destructive">
                                                {build.error}
                                            </p>
                                        )}
                                        {build.changelog.map((c) => (
                                            <div
                                                key={c.sha}
                                                className="font-mono text-xs"
                                            >
                                                <span className="text-muted-foreground">
                                                    {c.sha}
                                                </span>{' '}
                                                {c.subject}{' '}
                                                <span className="text-muted-foreground">
                                                    ({c.author})
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </details>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

BuildsIndex.layout = {
    breadcrumbs: [{ title: 'Builds', href: '/builds' }],
};
