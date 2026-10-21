import { Head, Link, useForm } from '@inertiajs/react';
import { Eye, RotateCcw, Save } from 'lucide-react';
import { useState } from 'react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
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
import { Textarea } from '@/components/ui/textarea';
import { useAbilities } from '@/hooks/use-abilities';

type Source = 'default' | 'custom' | 'repo';

type Props = {
    service: { name: string; slug: string };
    file: {
        name: string;
        description: string;
        source: Source;
        repo_path: string | null;
        contents: string;
        default_contents: string;
        template: boolean;
        placeholders: { scalars: string[]; blocks: string[] };
        buildFile: boolean;
        inert: boolean;
    };
    stacks: { id: number; label: string }[];
};

const TEXTAREA_ROWS = 28;

function Preview({
    slug,
    stacks,
}: {
    slug: string;
    stacks: Props['stacks'];
}) {
    const [stackId, setStackId] = useState(
        stacks[0] ? String(stacks[0].id) : '',
    );
    const [rendered, setRendered] = useState<{
        compose: string;
        nginx: string;
    } | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = async () => {
        setError(null);

        try {
            const response = await fetch(
                `/services/${slug}/files/preview/${stackId}`,
                { headers: { Accept: 'application/json' } },
            );

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setRendered(await response.json());
        } catch (e) {
            setError(
                `Could not render (${e instanceof Error ? e.message : 'network'}).`,
            );
        }
    };

    if (stacks.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Enable this service on a client's stack to preview what it
                would get.
            </p>
        );
    }

    return (
        <div className="grid gap-3">
            <p className="text-sm text-muted-foreground">
                What a stack gets with the files <b>as saved now</b>. Save
                first to preview an edit.
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <Select value={stackId} onValueChange={setStackId}>
                    <SelectTrigger className="w-64">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {stacks.map((s) => (
                            <SelectItem key={s.id} value={String(s.id)}>
                                {s.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => void load()}
                >
                    <Eye className="size-4" /> Preview
                </Button>
            </div>
            {error && <StatusPill tone="danger">{error}</StatusPill>}
            {rendered && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <div>
                        <p className="mb-1 font-mono text-xs text-muted-foreground">
                            docker-compose.yml
                        </p>
                        <pre className="max-h-[32rem] overflow-auto rounded-lg bg-muted p-3 font-mono text-xs">
                            {rendered.compose}
                        </pre>
                    </div>
                    <div>
                        <p className="mb-1 font-mono text-xs text-muted-foreground">
                            nginx vhost
                        </p>
                        <pre className="max-h-[32rem] overflow-auto rounded-lg bg-muted p-3 font-mono text-xs">
                            {rendered.nginx}
                        </pre>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function ServiceFilePage({ service, file, stacks }: Props) {
    const { manage: canManage } = useAbilities();
    const form = useForm({
        source: (file.source === 'default' ? 'custom' : file.source) as Source,
        contents: file.contents,
        repo_path: file.repo_path ?? '',
    });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(`/services/${service.slug}/files/${file.name}`, {
            preserveScroll: true,
        });
    };

    const reset = () => {
        if (
            confirm(
                `Put ${file.name} back to the control plane's default for ${service.name}?`,
            )
        ) {
            form.transform(() => ({ source: 'default' }));
            form.put(`/services/${service.slug}/files/${file.name}`, {
                preserveScroll: true,
                onFinish: () => form.transform((d) => d),
            });
        }
    };

    const allPlaceholders = [
        ...file.placeholders.scalars,
        ...file.placeholders.blocks,
    ];

    return (
        <>
            <Head title={`${file.name} · ${service.name}`} />
            <form
                onSubmit={save}
                className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6"
            >
                <PageHeader
                    eyebrow={service.name}
                    title={file.name}
                    description={file.description}
                    meta={
                        <>
                            {file.source === 'default' && (
                                <StatusPill tone="neutral">
                                    using the default
                                </StatusPill>
                            )}
                            {file.source === 'custom' && (
                                <StatusPill tone="info">customised</StatusPill>
                            )}
                            {file.source === 'repo' && (
                                <StatusPill tone="info">
                                    from repository: {file.repo_path}
                                </StatusPill>
                            )}
                            {file.inert && (
                                <StatusPill tone="warning">
                                    not used: the Dockerfile comes from the
                                    repository and does not copy this file
                                </StatusPill>
                            )}
                        </>
                    }
                    actions={
                        <Button asChild variant="outline">
                            <Link href={`/services/${service.slug}`}>
                                Back to {service.name}
                            </Link>
                        </Button>
                    }
                />

                {canManage && file.buildFile && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Where it comes from</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                        form.data.source === 'custom'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        form.setData('source', 'custom')
                                    }
                                >
                                    Edit here
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                        form.data.source === 'repo'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        form.setData('source', 'repo')
                                    }
                                >
                                    Use a file in the repository
                                </Button>
                            </div>
                            {form.data.source === 'repo' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="repo_path">
                                        Path in the repository
                                    </Label>
                                    <Input
                                        id="repo_path"
                                        className="max-w-md font-mono"
                                        placeholder="docker/Dockerfile"
                                        value={form.data.repo_path}
                                        onChange={(e) =>
                                            form.setData(
                                                'repo_path',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.repo_path}
                                    />
                                </div>
                            )}
                            <InputError message={form.errors.source} />
                        </CardContent>
                    </Card>
                )}

                {(form.data.source !== 'repo' || !file.buildFile) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Contents</CardTitle>
                            {file.template && (
                                <p className="text-sm text-muted-foreground">
                                    Placeholders filled per stack:{' '}
                                    {allPlaceholders.map((p, i) => (
                                        <span key={p}>
                                            <code className="rounded bg-muted px-1 font-mono text-xs">
                                                {`{{${p}}}`}
                                            </code>
                                            {i < allPlaceholders.length - 1
                                                ? ' '
                                                : ''}
                                        </span>
                                    ))}
                                    {file.placeholders.blocks.length > 0 && (
                                        <>
                                            {' '}
                                            —{' '}
                                            {file.placeholders.blocks
                                                .map((b) => `{{${b}}}`)
                                                .join(', ')}{' '}
                                            expand to several lines and must be
                                            alone on theirs.
                                        </>
                                    )}
                                </p>
                            )}
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            <Textarea
                                rows={TEXTAREA_ROWS}
                                spellCheck={false}
                                className="font-mono text-xs leading-relaxed"
                                readOnly={!canManage}
                                value={form.data.contents}
                                onChange={(e) =>
                                    form.setData('contents', e.target.value)
                                }
                            />
                            <InputError message={form.errors.contents} />
                            {canManage && (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        <Save className="size-4" /> Save
                                    </Button>
                                    {file.source !== 'default' && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={reset}
                                        >
                                            <RotateCcw className="size-4" />{' '}
                                            Reset to default
                                        </Button>
                                    )}
                                    {form.data.contents !==
                                        file.default_contents && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() =>
                                                form.setData(
                                                    'contents',
                                                    file.default_contents,
                                                )
                                            }
                                        >
                                            Load the default into the editor
                                        </Button>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {canManage && form.data.source === 'repo' && file.buildFile && (
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            <Save className="size-4" /> Save
                        </Button>
                    </div>
                )}

                {file.template && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Preview on a stack</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Preview slug={service.slug} stacks={stacks} />
                        </CardContent>
                    </Card>
                )}
            </form>
        </>
    );
}

ServiceFilePage.layout = {
    breadcrumbs: [{ title: 'Services', href: '/services' }],
};
