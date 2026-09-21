import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
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

type ServerOption = { id: number; name: string; hostname: string };

const slugify = (value: string) =>
    value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

export default function ClientCreate({ servers }: { servers: ServerOption[] }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        slug: '',
        domain: '',
        notes: '',
        server_id: servers[0]?.id ? String(servers[0].id) : '',
        testing_server_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/clients');
    };

    return (
        <>
            <Head title="Add client" />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-semibold">
                        Add client
                    </h1>
                    <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Creates both a live and a testing stack. Nothing is
                        deployed until you enable services and run a release.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Identity</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => {
                                    setData((current) => ({
                                        ...current,
                                        name: e.target.value,
                                        slug:
                                            current.slug === '' ||
                                            current.slug ===
                                                slugify(current.name)
                                                ? slugify(e.target.value)
                                                : current.slug,
                                    }));
                                }}
                                placeholder="ACME Pizza"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="slug">Slug</Label>
                            <Input
                                id="slug"
                                value={data.slug}
                                onChange={(e) =>
                                    setData('slug', e.target.value)
                                }
                                className="font-mono"
                                placeholder="acme-pizza"
                            />
                            <InputError message={errors.slug} />
                            <p className="text-xs text-muted-foreground">
                                Used for compose project names and paths on the
                                server. It never appears in a repository, branch
                                or image name.
                            </p>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="domain">Domain</Label>
                            <Input
                                id="domain"
                                value={data.domain}
                                onChange={(e) =>
                                    setData('domain', e.target.value)
                                }
                                className="font-mono"
                                placeholder="acme.cloud"
                            />
                            <InputError message={errors.domain} />
                            <p className="text-xs text-muted-foreground">
                                Service subdomains are derived from this:
                                hiring.{data.domain || 'acme.cloud'} for live,
                                hiringtesting.{data.domain || 'acme.cloud'} for
                                testing.
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Where it runs</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label>Server</Label>
                            <Select
                                value={data.server_id}
                                onValueChange={(v) => setData('server_id', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Choose a server" />
                                </SelectTrigger>
                                <SelectContent>
                                    {servers.map((server) => (
                                        <SelectItem
                                            key={server.id}
                                            value={String(server.id)}
                                        >
                                            {server.name} ({server.hostname})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.server_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Testing server (optional)</Label>
                            <Select
                                value={data.testing_server_id}
                                onValueChange={(v) =>
                                    setData('testing_server_id', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Same as live" />
                                </SelectTrigger>
                                <SelectContent>
                                    {servers.map((server) => (
                                        <SelectItem
                                            key={server.id}
                                            value={String(server.id)}
                                        >
                                            {server.name} ({server.hostname})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                Leave blank to put both tiers on one machine, as
                                the current estate does. Moving testing to its
                                own box later is a change to this field.
                            </p>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notes</Label>
                            <Textarea
                                id="notes"
                                rows={2}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                            />
                        </div>
                    </CardContent>
                </Card>

                <div>
                    <Button
                        type="submit"
                        disabled={processing || servers.length === 0}
                    >
                        Create client
                    </Button>
                    {servers.length === 0 && (
                        <p className="mt-2 text-xs text-destructive">
                            Add a server first — a stack has to run somewhere.
                        </p>
                    )}
                </div>
            </form>
        </>
    );
}

ClientCreate.layout = {
    breadcrumbs: [
        { title: 'Clients', href: '/clients' },
        { title: 'Add', href: '/clients/create' },
    ],
};
