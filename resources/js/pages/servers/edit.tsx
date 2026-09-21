import { Head, router, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { ServerDetail } from '@/types/server';

export default function ServerEdit({ server }: { server: ServerDetail }) {
    const { data, setData, put, processing, errors } = useForm({
        name: server.name,
        hostname: server.hostname,
        ip_address: server.ip_address ?? '',
        ssh_user: server.ssh_user,
        ssh_port: server.ssh_port,
        ssh_private_key: '',
        notes: server.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/servers/${server.id}`);
    };

    const remove = () => {
        if (
            confirm(
                `Remove ${server.name}? This deletes the stored key but touches nothing on the machine itself.`,
            )
        ) {
            router.delete(`/servers/${server.id}`);
        }
    };

    const movingHost =
        data.hostname !== server.hostname || data.ssh_port !== server.ssh_port;

    return (
        <>
            <Head title={`Edit ${server.name}`} />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <h1 className="font-display text-3xl font-semibold">
                    Edit {server.name}
                </h1>

                <Card>
                    <CardHeader>
                        <CardTitle>Connection</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="hostname">Hostname</Label>
                                <Input
                                    id="hostname"
                                    value={data.hostname}
                                    onChange={(e) =>
                                        setData('hostname', e.target.value)
                                    }
                                />
                                <InputError message={errors.hostname} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ip_address">IP address</Label>
                                <Input
                                    id="ip_address"
                                    value={data.ip_address}
                                    onChange={(e) =>
                                        setData('ip_address', e.target.value)
                                    }
                                />
                                <InputError message={errors.ip_address} />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="ssh_user">SSH user</Label>
                                <Input
                                    id="ssh_user"
                                    value={data.ssh_user}
                                    onChange={(e) =>
                                        setData('ssh_user', e.target.value)
                                    }
                                />
                                <InputError message={errors.ssh_user} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ssh_port">SSH port</Label>
                                <Input
                                    id="ssh_port"
                                    type="number"
                                    min={1}
                                    max={65535}
                                    value={data.ssh_port}
                                    onChange={(e) =>
                                        setData(
                                            'ssh_port',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                                <InputError message={errors.ssh_port} />
                            </div>
                        </div>

                        {movingHost && (
                            <p className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-xs">
                                Changing the host or port discards the pinned
                                host key and reads the new one, because the
                                current key belongs to the old address.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Replace private key</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2">
                        <Textarea
                            id="ssh_private_key"
                            rows={6}
                            className="font-mono text-xs"
                            value={data.ssh_private_key}
                            onChange={(e) =>
                                setData('ssh_private_key', e.target.value)
                            }
                            placeholder="Leave blank to keep the current key"
                        />
                        <InputError message={errors.ssh_private_key} />
                        <p className="text-xs text-muted-foreground">
                            The stored key is never shown. Leave this empty
                            unless you are rotating it.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Notes</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Textarea
                            id="notes"
                            rows={3}
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                    </CardContent>
                </Card>

                <div className="flex justify-between gap-2">
                    <Button type="submit" disabled={processing}>
                        Save changes
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={remove}
                    >
                        Remove server
                    </Button>
                </div>
            </form>
        </>
    );
}

ServerEdit.layout = {
    breadcrumbs: [{ title: 'Servers', href: '/servers' }],
};
