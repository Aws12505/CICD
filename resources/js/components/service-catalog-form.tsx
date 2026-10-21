import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

export type ServiceCatalog = {
    auth_service_name: string | null;
    identity_provider: boolean;
    build_assets: boolean;
    extra_databases: string[];
    processes: { name: string; command: string; port?: number | null; path?: string | null }[];
    first_run_commands: string[];
    every_deploy_commands: string[];
};

const lines = (text: string) =>
    text
        .split('\n')
        .map((l) => l.trim())
        .filter(Boolean);

/**
 * How a service runs on a stack: its pizzasys name, extra databases, the
 * processes beside its web container and the commands a deploy runs.
 */
export function ServiceCatalogForm({
    slug,
    catalog,
    canManage,
}: {
    slug: string;
    catalog: ServiceCatalog;
    canManage: boolean;
}) {
    const form = useForm({
        auth_service_name: catalog.auth_service_name ?? '',
        identity_provider: catalog.identity_provider,
        build_assets: catalog.build_assets,
        extra_databases: catalog.extra_databases.join(', '),
        processes: catalog.processes.map((p) => ({
            name: p.name,
            command: p.command,
            port: p.port ? String(p.port) : '',
            path: p.path ?? '',
        })),
        first_run_commands: catalog.first_run_commands.join('\n'),
        every_deploy_commands: catalog.every_deploy_commands.join('\n'),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            auth_service_name: data.auth_service_name || null,
            identity_provider: data.identity_provider,
            build_assets: data.build_assets,
            extra_databases: data.extra_databases
                .split(',')
                .map((d) => d.trim())
                .filter(Boolean),
            processes: data.processes
                .filter((p) => p.name.trim() !== '' || p.command.trim() !== '')
                .map((p) => ({
                    name: p.name.trim(),
                    command: p.command.trim(),
                    port: p.port.trim() === '' ? null : Number(p.port),
                    path: p.path.trim() === '' ? null : p.path.trim(),
                })),
            first_run_commands: lines(data.first_run_commands),
            every_deploy_commands: lines(data.every_deploy_commands),
        }));
        form.put(`/services/${slug}/catalog`, { preserveScroll: true });
    };

    const setProcess = (
        index: number,
        field: 'name' | 'command' | 'port' | 'path',
        value: string,
    ) =>
        form.setData(
            'processes',
            form.data.processes.map((p, i) =>
                i === index ? { ...p, [field]: value } : p,
            ),
        );

    const errorFor = (prefix: string) =>
        Object.entries(form.errors).find(([k]) => k.startsWith(prefix))?.[1];

    return (
        <Card>
            <CardHeader>
                <CardTitle>How it runs</CardTitle>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="grid gap-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="auth_service_name">
                                Name in pizzasys auth rules
                            </Label>
                            <Input
                                id="auth_service_name"
                                disabled={!canManage}
                                value={form.data.auth_service_name}
                                onChange={(e) =>
                                    form.setData(
                                        'auth_service_name',
                                        e.target.value,
                                    )
                                }
                                placeholder="Hiring"
                            />
                            <p className="text-xs text-muted-foreground">
                                Becomes AUTH_SERVER_SERVICE_NAME and the
                                service-client name, so the two always match the
                                rules.
                            </p>
                            <InputError
                                message={form.errors.auth_service_name}
                            />
                            <label className="mt-1 flex items-center gap-2 text-sm">
                                <Switch
                                    disabled={!canManage}
                                    checked={form.data.identity_provider}
                                    onCheckedChange={(v) =>
                                        form.setData('identity_provider', v)
                                    }
                                />
                                This is the identity provider (pizzasys)
                            </label>
                            <p className="text-xs text-muted-foreground">
                                Deployed first; registers every other service as
                                a client and creates the first admin.
                            </p>
                            <label className="mt-1 flex items-center gap-2 text-sm">
                                <Switch
                                    disabled={!canManage}
                                    checked={form.data.build_assets}
                                    onCheckedChange={(v) =>
                                        form.setData('build_assets', v)
                                    }
                                />
                                Build the front end (Vite) into the image
                            </label>
                            <p className="text-xs text-muted-foreground">
                                Off for a service that is an API only: a front
                                end left in its repository is then never built.
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="extra_databases">
                                Extra databases
                            </Label>
                            <Input
                                id="extra_databases"
                                className="font-mono"
                                disabled={!canManage}
                                value={form.data.extra_databases}
                                onChange={(e) =>
                                    form.setData(
                                        'extra_databases',
                                        e.target.value,
                                    )
                                }
                                placeholder="hiring_analytics, hiring_archive"
                            />
                            <p className="text-xs text-muted-foreground">
                                Created and granted on every stack, with
                                _testing added on testing.
                            </p>
                            <InputError message={errorFor('extra_databases')} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label>Processes beside the web container</Label>
                        <p className="text-xs text-muted-foreground">
                            This is the supervisord.conf of the old setup: one
                            line per program, and each becomes its own
                            container from the same image. Port and path are
                            only for one that listens (Reverb: 8080 and /app).
                        </p>
                        <div className="grid gap-2">
                            {form.data.processes.map((p, i) => (
                                <div
                                    key={i}
                                    className="grid gap-2 sm:grid-cols-[8rem_1fr_5rem_6rem_auto]"
                                >
                                    <Input
                                        aria-label="Process name"
                                        className="font-mono"
                                        placeholder="queue"
                                        disabled={!canManage}
                                        value={p.name}
                                        onChange={(e) =>
                                            setProcess(i, 'name', e.target.value)
                                        }
                                    />
                                    <Input
                                        aria-label="Command"
                                        className="font-mono"
                                        placeholder="php artisan queue:work --sleep=3 --tries=1"
                                        disabled={!canManage}
                                        value={p.command}
                                        onChange={(e) =>
                                            setProcess(
                                                i,
                                                'command',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <Input
                                        aria-label="Port"
                                        className="font-mono"
                                        placeholder="port"
                                        disabled={!canManage}
                                        value={p.port}
                                        onChange={(e) =>
                                            setProcess(i, 'port', e.target.value)
                                        }
                                    />
                                    <Input
                                        aria-label="Path"
                                        className="font-mono"
                                        placeholder="/path"
                                        disabled={!canManage}
                                        value={p.path}
                                        onChange={(e) =>
                                            setProcess(i, 'path', e.target.value)
                                        }
                                    />
                                    {canManage && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                form.setData(
                                                    'processes',
                                                    form.data.processes.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </div>
                            ))}
                            {canManage && (
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            form.setData('processes', [
                                                ...form.data.processes,
                                                {
                                                    name: '',
                                                    command: '',
                                                    port: '',
                                                    path: '',
                                                },
                                            ])
                                        }
                                    >
                                        Add a process
                                    </Button>
                                </div>
                            )}
                        </div>
                        <InputError message={errorFor('processes')} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="first_run_commands">
                                First deploy only
                            </Label>
                            <Textarea
                                id="first_run_commands"
                                rows={4}
                                className="font-mono text-xs"
                                disabled={!canManage}
                                value={form.data.first_run_commands}
                                onChange={(e) =>
                                    form.setData(
                                        'first_run_commands',
                                        e.target.value,
                                    )
                                }
                                placeholder="php artisan db:seed --class=CatalogSeeder --force"
                            />
                            <InputError
                                message={errorFor('first_run_commands')}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="every_deploy_commands">
                                Every deploy, after migrations
                            </Label>
                            <Textarea
                                id="every_deploy_commands"
                                rows={4}
                                className="font-mono text-xs"
                                disabled={!canManage}
                                value={form.data.every_deploy_commands}
                                onChange={(e) =>
                                    form.setData(
                                        'every_deploy_commands',
                                        e.target.value,
                                    )
                                }
                                placeholder="php artisan db:seed --class=AuthRulesSeeder --force"
                            />
                            <p className="text-xs text-muted-foreground">
                                Must be safe to run again.
                            </p>
                            <InputError
                                message={errorFor('every_deploy_commands')}
                            />
                        </div>
                    </div>

                    {canManage && (
                        <div>
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                        </div>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}
