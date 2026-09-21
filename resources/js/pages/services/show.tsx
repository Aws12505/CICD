import { Head, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { useAbilities } from '@/hooks/use-abilities';
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
import { Switch } from '@/components/ui/switch';
import type { EnvDefinition, ServiceRow } from '@/types/topology';

type ServiceDetail = ServiceRow & {
    repo_full_name: string;
    default_branch: string;
    dockerfile_path: string | null;
    database_name: string | null;
    health_path: string;
    image_repository: string;
};

type Props = {
    service: ServiceDetail;
    definitions: EnvDefinition[];
    sources: Record<string, string>;
};

export default function ServiceShow({ service, definitions, sources }: Props) {
    const { manage: canManage } = useAbilities();

    const form = useForm({
        key: '',
        source: 'literal',
        required: true as boolean,
        is_build_arg: false as boolean,
        default_value: '',
        description: '',
    });

    const addKey: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(`/services/${service.slug}/env`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <>
            <Head title={service.name} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <div className="flex items-center gap-3">
                        <h1 className="font-display text-3xl font-semibold">
                            {service.name}
                        </h1>
                        {service.per_stack_build && (
                            <Badge variant="secondary">built per stack</Badge>
                        )}
                    </div>
                    <p className="mt-1 font-mono text-sm text-muted-foreground">
                        {service.repo_full_name} · {service.default_branch}
                    </p>
                    <p className="mt-1 font-mono text-xs text-muted-foreground">
                        {service.image_repository}
                    </p>
                </div>

                <Card>
                    <CardHeader className="border-b border-sidebar-border/70">
                        <div className="flex items-center justify-between">
                            <CardTitle>Environment</CardTitle>
                            <Badge variant="secondary">
                                {definitions.length} keys
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                            {definitions.length === 0 && (
                                <p className="p-4 text-sm text-muted-foreground">
                                    Nothing declared yet. A required key with no
                                    value stops a deploy before anything swaps,
                                    which is the point of declaring them.
                                </p>
                            )}
                            {definitions.map((definition) => (
                                <div
                                    key={definition.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-mono text-sm">
                                                {definition.key}
                                            </span>
                                            <Badge variant="secondary">
                                                {sources[definition.source] ??
                                                    definition.source}
                                            </Badge>
                                            {definition.required && (
                                                <Badge variant="outline">
                                                    required
                                                </Badge>
                                            )}
                                            {definition.is_build_arg && (
                                                <Badge variant="outline">
                                                    build arg
                                                </Badge>
                                            )}
                                        </div>
                                        {definition.default_value && (
                                            <div className="mt-1 truncate font-mono text-xs text-muted-foreground">
                                                {definition.default_value}
                                            </div>
                                        )}
                                    </div>
                                    {canManage && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                router.delete(
                                                    `/services/${service.slug}/env/${definition.id}`,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {canManage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Declare an environment key</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={addKey} className="grid gap-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="key">Key</Label>
                                        <Input
                                            id="key"
                                            className="font-mono"
                                            value={form.data.key}
                                            onChange={(e) =>
                                                form.setData(
                                                    'key',
                                                    e.target.value.toUpperCase(),
                                                )
                                            }
                                            placeholder="AUTH_SERVER_BASE_URL"
                                        />
                                        <InputError message={form.errors.key} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>Source</Label>
                                        <Select
                                            value={form.data.source}
                                            onValueChange={(v) =>
                                                form.setData('source', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(sources).map(
                                                    ([value, label]) => (
                                                        <SelectItem
                                                            key={value}
                                                            value={value}
                                                        >
                                                            {label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="default_value">
                                        Default / derivation
                                    </Label>
                                    <Input
                                        id="default_value"
                                        className="font-mono"
                                        value={form.data.default_value}
                                        onChange={(e) =>
                                            form.setData(
                                                'default_value',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="auth:/api/v1 · database · app_url · redis_index"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        For a derived source this names what to
                                        derive: another service as
                                        <span className="font-mono">
                                            {' '}
                                            slug:/path
                                        </span>
                                        , or a stack property such as
                                        <span className="font-mono">
                                            {' '}
                                            database
                                        </span>
                                        ,
                                        <span className="font-mono">
                                            {' '}
                                            app_url
                                        </span>
                                        ,
                                        <span className="font-mono">
                                            {' '}
                                            redis_index
                                        </span>
                                        ,
                                        <span className="font-mono">
                                            {' '}
                                            dev_mode
                                        </span>
                                        .
                                    </p>
                                </div>

                                <div className="flex flex-wrap gap-6">
                                    <label className="flex items-center gap-2 text-sm">
                                        <Switch
                                            checked={form.data.required}
                                            onCheckedChange={(v) =>
                                                form.setData('required', v)
                                            }
                                        />
                                        Required
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <Switch
                                            checked={form.data.is_build_arg}
                                            onCheckedChange={(v) =>
                                                form.setData('is_build_arg', v)
                                            }
                                        />
                                        Build argument
                                    </label>
                                </div>

                                <div>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        Save key
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

ServiceShow.layout = {
    breadcrumbs: [{ title: 'Services', href: '/services' }],
};
