import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Plus, Play } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

type Definition = {
    key: string;
    source: string;
    required: boolean;
    is_build_arg: boolean;
    description: string | null;
    default_value: string | null;
    derived: boolean;
    secret: boolean;
};

type ServiceEnv = {
    id: number;
    name: string;
    slug: string;
    definitions: Definition[];
};

type Row = {
    scope: string;
    service_id: number | null;
    key: string;
    value: string | null;
    is_secret: boolean;
};

type StackEnv = {
    id: number;
    tier: string;
    status: string;
    deployed: boolean;
    services: Record<
        string,
        {
            missing: string[];
            resolved: Record<string, { value: string; origin: string }>;
        }
    >;
};

type Props = {
    client: { id: number; name: string; slug: string };
    scopes: { key: string; label: string }[];
    services: ServiceEnv[];
    values: Row[];
    stacks: StackEnv[];
};

type Edit = { value: string; is_secret: boolean; remove: boolean };

const id = (scope: string, serviceId: number | null, key: string) =>
    `${scope}|${serviceId ?? 'all'}|${key}`;

function KeyRow({
    label,
    description,
    badges,
    row,
    edit,
    secretByDefault,
    placeholder,
    onChange,
}: {
    label: string;
    description?: string | null;
    badges: string[];
    row: Row | undefined;
    edit: Edit | undefined;
    secretByDefault: boolean;
    placeholder: string;
    onChange: (edit: Edit) => void;
}) {
    const isSecret = edit?.is_secret ?? row?.is_secret ?? secretByDefault;
    const stored = row !== undefined && !(edit?.remove ?? false);
    const value =
        edit?.value ?? (row && !row.is_secret ? (row.value ?? '') : '');

    return (
        <div className="grid gap-2 p-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] md:items-start">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-sm break-all">{label}</span>
                    {badges.map((b) => (
                        <Badge key={b} variant="outline">
                            {b}
                        </Badge>
                    ))}
                    {stored && isSecret && (
                        <Badge variant="secondary">saved</Badge>
                    )}
                </div>
                {description && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            <div className="flex items-center gap-2">
                <Input
                    className="font-mono"
                    type={isSecret ? 'password' : 'text'}
                    autoComplete="off"
                    value={value}
                    placeholder={
                        stored && isSecret
                            ? 'Leave empty to keep the saved value'
                            : placeholder
                    }
                    onChange={(e) =>
                        onChange({
                            value: e.target.value,
                            is_secret: isSecret,
                            remove: false,
                        })
                    }
                />
                <label className="flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                    <Switch
                        checked={isSecret}
                        onCheckedChange={(next) =>
                            onChange({
                                value: edit?.value ?? '',
                                is_secret: next,
                                remove: false,
                            })
                        }
                    />
                    secret
                </label>
                {stored && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onChange({
                                value: '',
                                is_secret: isSecret,
                                remove: !(edit?.remove ?? false),
                            })
                        }
                    >
                        {edit?.remove ? 'Keep' : 'Clear'}
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function ClientEnv({
    client,
    scopes,
    services,
    values,
    stacks,
}: Props) {
    const [scope, setScope] = useState(scopes[0]?.key ?? 'client');
    const [edits, setEdits] = useState<Record<string, Edit>>({});
    const [custom, setCustom] = useState<
        { serviceId: number | null; key: string }[]
    >([]);
    const [saving, setSaving] = useState(false);

    const rows = useMemo(() => {
        const map: Record<string, Row> = {};
        values.forEach((r) => (map[id(r.scope, r.service_id, r.key)] = r));

        return map;
    }, [values]);

    const dirty = Object.keys(edits).length;

    const change = (key: string, edit: Edit) =>
        setEdits((current) => ({ ...current, [key]: edit }));

    const save = () => {
        setSaving(true);
        router.put(
            `/clients/${client.slug}/env`,
            {
                entries: Object.entries(edits).map(([k, e]) => {
                    const [s, svc, key] = k.split('|');

                    return {
                        scope: s,
                        service_id: svc === 'all' ? null : Number(svc),
                        key,
                        value: e.value,
                        is_secret: e.is_secret,
                        remove: e.remove,
                    };
                }),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEdits({});
                    setCustom([]);
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const groups: {
        serviceId: number | null;
        title: string;
        definitions: Definition[];
    }[] = [
        { serviceId: null, title: 'Every service', definitions: [] },
        ...services.map((s) => ({
            serviceId: s.id,
            title: s.name,
            definitions: s.definitions,
        })),
    ];

    const extraKeys = (serviceId: number | null, definitions: Definition[]) => {
        const declared = new Set(definitions.map((d) => d.key));
        const keys = values
            .filter(
                (r) =>
                    r.scope === scope &&
                    r.service_id === serviceId &&
                    !declared.has(r.key),
            )
            .map((r) => r.key);
        custom
            .filter((c) => c.serviceId === serviceId && c.key)
            .forEach((c) => keys.includes(c.key) || keys.push(c.key));

        return keys;
    };

    return (
        <>
            <Head title={`${client.name} · environment`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">
                            {client.name} · environment
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            Set a value once for both tiers, and override it on
                            one tier only where they differ. Passwords, keys,
                            database and service URLs are worked out by the
                            control plane and are not typed here. Saving changes
                            nothing on the servers until you click Apply.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={`/clients/${client.slug}`}>
                            Back to {client.name}
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    {stacks.map((stack) => {
                        const missing = Object.entries(stack.services).filter(
                            ([, s]) => s.missing.length > 0,
                        );

                        return (
                            <Card key={stack.id}>
                                <CardHeader className="flex-row items-center justify-between gap-2 space-y-0">
                                    <CardTitle className="capitalize">
                                        {stack.tier}
                                    </CardTitle>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={dirty > 0}
                                        title={
                                            dirty > 0
                                                ? 'Save first'
                                                : 'Restart what reads these values'
                                        }
                                        onClick={() =>
                                            router.post(
                                                `/stacks/${stack.id}/apply`,
                                            )
                                        }
                                    >
                                        <Play className="size-4" /> Apply
                                    </Button>
                                </CardHeader>
                                <CardContent className="grid gap-2 text-sm">
                                    {missing.length === 0 ? (
                                        <p className="text-muted-foreground">
                                            Every required value is set.
                                            {!stack.deployed &&
                                                ' Nothing is deployed yet.'}
                                        </p>
                                    ) : (
                                        missing.map(([slug, s]) => (
                                            <div
                                                key={slug}
                                                className="flex items-start gap-2 text-destructive"
                                            >
                                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                                <span>
                                                    <span className="font-medium">
                                                        {slug}
                                                    </span>{' '}
                                                    is missing{' '}
                                                    <span className="font-mono">
                                                        {s.missing.join(', ')}
                                                    </span>
                                                </span>
                                            </div>
                                        ))
                                    )}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={scope}
                        onValueChange={(v) => v && setScope(v)}
                    >
                        {scopes.map((s) => (
                            <ToggleGroupItem key={s.key} value={s.key}>
                                {s.label}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                    <Button onClick={save} disabled={dirty === 0 || saving}>
                        Save {dirty > 0 ? `(${dirty})` : ''}
                    </Button>
                </div>

                {groups.map((group) => {
                    const typed = group.definitions.filter((d) => !d.derived);
                    const derived = group.definitions.filter((d) => d.derived);
                    const extras = extraKeys(
                        group.serviceId,
                        group.definitions,
                    );
                    const slug = services.find(
                        (s) => s.id === group.serviceId,
                    )?.slug;

                    return (
                        <Card key={group.serviceId ?? 'all'}>
                            <CardHeader className="border-b border-sidebar-border/70">
                                <CardTitle>{group.title}</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                    {typed.map((d) => {
                                        const k = id(
                                            scope,
                                            group.serviceId,
                                            d.key,
                                        );

                                        return (
                                            <KeyRow
                                                key={k}
                                                label={d.key}
                                                description={d.description}
                                                badges={[
                                                    ...(d.required
                                                        ? ['required']
                                                        : []),
                                                    ...(d.is_build_arg
                                                        ? ['build arg']
                                                        : []),
                                                ]}
                                                row={rows[k]}
                                                edit={edits[k]}
                                                secretByDefault={d.secret}
                                                placeholder={
                                                    scope === 'client'
                                                        ? (d.default_value ??
                                                          '')
                                                        : 'Same as both tiers'
                                                }
                                                onChange={(e) => change(k, e)}
                                            />
                                        );
                                    })}

                                    {extras.map((key) => {
                                        const k = id(
                                            scope,
                                            group.serviceId,
                                            key,
                                        );

                                        return (
                                            <KeyRow
                                                key={k}
                                                label={key}
                                                badges={['custom']}
                                                row={rows[k]}
                                                edit={edits[k]}
                                                secretByDefault={false}
                                                placeholder=""
                                                onChange={(e) => change(k, e)}
                                            />
                                        );
                                    })}

                                    <AddKey
                                        onAdd={(key) =>
                                            setCustom((c) => [
                                                ...c,
                                                {
                                                    serviceId: group.serviceId,
                                                    key,
                                                },
                                            ])
                                        }
                                    />

                                    {derived.length > 0 && (
                                        <details className="p-4">
                                            <summary className="cursor-pointer text-sm text-muted-foreground">
                                                {derived.length} values worked
                                                out by the control plane
                                            </summary>
                                            <div className="mt-3 grid gap-1">
                                                {derived.map((d) => (
                                                    <div
                                                        key={d.key}
                                                        className="grid gap-1 font-mono text-xs sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
                                                    >
                                                        <span className="break-all">
                                                            {d.key}
                                                        </span>
                                                        <span className="break-all text-muted-foreground">
                                                            {stacks
                                                                .filter(
                                                                    (s) =>
                                                                        scope ===
                                                                            'client' ||
                                                                        s.tier ===
                                                                            scope,
                                                                )
                                                                .map(
                                                                    (s) =>
                                                                        `${s.tier}: ${
                                                                            (slug &&
                                                                                s
                                                                                    .services[
                                                                                    slug
                                                                                ]
                                                                                    ?.resolved[
                                                                                    d
                                                                                        .key
                                                                                ]
                                                                                    ?.value) ??
                                                                            '—'
                                                                        }`,
                                                                )
                                                                .join('  ·  ')}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </details>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </>
    );
}

function AddKey({ onAdd }: { onAdd: (key: string) => void }) {
    const [key, setKey] = useState('');
    const valid = /^[A-Z_][A-Z0-9_]*$/.test(key);

    return (
        <div className="flex items-center gap-2 p-4">
            <Input
                className="max-w-xs font-mono"
                placeholder="ANOTHER_KEY"
                value={key}
                onChange={(e) => setKey(e.target.value.toUpperCase())}
            />
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!valid}
                onClick={() => {
                    onAdd(key);
                    setKey('');
                }}
            >
                <Plus className="size-4" /> Add a key
            </Button>
        </div>
    );
}

ClientEnv.layout = {
    breadcrumbs: [{ title: 'Clients', href: '/clients' }],
};
