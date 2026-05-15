import { Head, router, useForm } from '@inertiajs/react';
import { Copy, RefreshCw } from 'lucide-react';
import type { FormEventHandler } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface Project {
    id: number;
    name: string;
    repo_owner: string | null;
    repo_name: string;
    repo_full_name: string | null;
    description: string | null;
    github_secret: string;
}

export default function ProjectEdit({ project }: { project: Project }) {
    const { data, setData, put, processing, errors } = useForm({
        name: project.name,
        repo_owner: project.repo_owner ?? '',
        repo_name: project.repo_name,
        description: project.description ?? '',
        github_secret: project.github_secret,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/projects/${project.id}`);
    };

    const destroy = () => {
        if (confirm('Delete this project and all its pipelines and runs?')) {
            router.delete(`/projects/${project.id}`);
        }
    };

    const webhookUrl =
        typeof window === 'undefined'
            ? '/webhook/github'
            : `${window.location.origin}/webhook/github`;

    const copyValue = async (value: string, label: string) => {
        try {
            await navigator.clipboard.writeText(value);
            toast.success(`${label} copied`);
        } catch {
            toast.error('Copy failed');
        }
    };

    const fullName =
        data.repo_owner && data.repo_name
            ? `${data.repo_owner}/${data.repo_name}`
            : '';

    return (
        <>
            <Head title={`Edit ${project.name}`} />
            <div className="mx-auto w-full max-w-2xl p-4">
                <h1 className="font-display mb-2 text-3xl font-semibold">
                    Edit project
                </h1>
                <p className="mb-6 text-sm text-muted-foreground">
                    Update repository details or rotate the project webhook
                    secret.
                </p>

                <Card>
                    <CardHeader>
                        <CardTitle>Project details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Display name</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="repo_owner">
                                    GitHub repo owner
                                </Label>
                                <Input
                                    id="repo_owner"
                                    value={data.repo_owner}
                                    className="font-mono"
                                    onChange={(e) =>
                                        setData('repo_owner', e.target.value)
                                    }
                                />
                                <InputError message={errors.repo_owner} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="repo_name">
                                    GitHub repo name
                                </Label>
                                <Input
                                    id="repo_name"
                                    value={data.repo_name}
                                    className="font-mono"
                                    onChange={(e) =>
                                        setData('repo_name', e.target.value)
                                    }
                                />
                                <InputError message={errors.repo_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label>Repository full name</Label>
                                <Input
                                    value={fullName}
                                    readOnly
                                    className="font-mono text-xs"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="github_secret">
                                    Webhook secret
                                </Label>
                                <div className="flex gap-2">
                                    <Input
                                        id="github_secret"
                                        value={data.github_secret}
                                        className="font-mono text-xs"
                                        onChange={(e) =>
                                            setData(
                                                'github_secret',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        title="Generate new"
                                        onClick={() =>
                                            setData(
                                                'github_secret',
                                                crypto
                                                    .randomUUID()
                                                    .replace(/-/g, ''),
                                            )
                                        }
                                    >
                                        <RefreshCw className="size-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        onClick={() =>
                                            copyValue(
                                                data.github_secret,
                                                'Webhook secret',
                                            )
                                        }
                                    >
                                        <Copy className="size-4" />
                                    </Button>
                                </div>
                                <InputError message={errors.github_secret} />
                                <p className="text-xs text-muted-foreground">
                                    Payload URL:{' '}
                                    <span className="font-mono">
                                        {webhookUrl}
                                    </span>
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    rows={3}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                />
                                <InputError message={errors.description} />
                            </div>

                            <div className="flex justify-between">
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={destroy}
                                >
                                    Delete project
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Save changes
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ProjectEdit.layout = {
    breadcrumbs: [{ title: 'Projects', href: '/projects' }],
};
