import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export default function ServerCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        hostname: '',
        ip_address: '',
        ssh_user: 'deploy',
        ssh_port: 22,
        ssh_private_key: '',
        notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/servers');
    };

    return (
        <>
            <Head title="Add server" />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-semibold">
                        Add server
                    </h1>
                    <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                        The host key is read as soon as this is saved, so every
                        later connection can be pinned to it.
                    </p>
                </div>

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
                                placeholder="ACME live"
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
                                    placeholder="acme.example.com"
                                />
                                <InputError message={errors.hostname} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ip_address">
                                    IP address (optional)
                                </Label>
                                <Input
                                    id="ip_address"
                                    value={data.ip_address}
                                    onChange={(e) =>
                                        setData('ip_address', e.target.value)
                                    }
                                    placeholder="10.0.0.4"
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
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Private key</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Textarea
                                id="ssh_private_key"
                                rows={8}
                                className="font-mono text-xs"
                                value={data.ssh_private_key}
                                onChange={(e) =>
                                    setData('ssh_private_key', e.target.value)
                                }
                                placeholder={
                                    '-----BEGIN OPENSSH PRIVATE KEY-----'
                                }
                            />
                            <InputError message={errors.ssh_private_key} />
                            <p className="text-xs text-muted-foreground">
                                Encrypted before it is stored, and never sent
                                back to the browser. Use a key dedicated to this
                                control plane so it can be revoked on its own.
                            </p>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notes (optional)</Label>
                            <Textarea
                                id="notes"
                                rows={2}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                            />
                            <InputError message={errors.notes} />
                        </div>
                    </CardContent>
                </Card>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Add server
                    </Button>
                </div>
            </form>
        </>
    );
}

ServerCreate.layout = {
    breadcrumbs: [
        { title: 'Servers', href: '/servers' },
        { title: 'Add', href: '/servers/create' },
    ],
};
