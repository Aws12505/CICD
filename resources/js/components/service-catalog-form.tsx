import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export type ServiceCatalog = {
    auth_service_name: string | null;
    extra_databases: string[];
    processes: { name: string; command: string }[];
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
        extra_databases: catalog.extra_databases.join(', '),
        processes: catalog.processes
            .map((p) => `${p.name}: ${p.command}`)
            .join('\n'),
        first_run_commands: catalog.first_run_commands.join('\n'),
        every_deploy_commands: catalog.every_deploy_commands.join('\n'),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            auth_service_name: data.auth_service_name || null,
            extra_databases: data.extra_databases
                .split(',')
                .map((d) => d.trim())
                .filter(Boolean),
            processes: lines(data.processes).map((line) => {
                const at = line.indexOf(':');

                return at === -1
                    ? { name: line, command: '' }
                    : {
                          name: line.slice(0, at).trim(),
                          command: line.slice(at + 1).trim(),
                      };
            }),
            first_run_commands: lines(data.first_run_commands),
            every_deploy_commands: lines(data.every_deploy_commands),
        }));
        form.put(`/services/${slug}/catalog`, { preserveScroll: true });
    };

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
                        <Label htmlFor="processes">
                            Processes beside the web container
                        </Label>
                        <Textarea
                            id="processes"
                            rows={4}
                            className="font-mono text-xs"
                            disabled={!canManage}
                            value={form.data.processes}
                            onChange={(e) =>
                                form.setData('processes', e.target.value)
                            }
                            placeholder={
                                'queue: php artisan queue:work --queue=humanity,default\nscheduler: php artisan schedule:work\nnats: php artisan nats:consume'
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            One per line, as name: command. Each runs in its own
                            container from the same image.
                        </p>
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
