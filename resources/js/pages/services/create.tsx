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
import { Switch } from '@/components/ui/switch';

const slugify = (value: string) =>
    value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

export default function ServiceCreate({
    recipes,
}: {
    recipes: Record<string, string>;
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        slug: '',
        repo_full_name: '',
        default_branch: 'testing',
        build_recipe: 'laravel-api',
        dockerfile_path: '',
        subdomain: '',
        database_name: '',
        health_path: '/up',
        per_stack_build: false as boolean,
        position: 0,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/services');
    };

    return (
        <>
            <Head title="Add service" />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <h1 className="font-display text-3xl font-semibold">
                    Add service
                </h1>

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
                                onChange={(e) =>
                                    setData((c) => ({
                                        ...c,
                                        name: e.target.value,
                                        slug:
                                            c.slug === '' ||
                                            c.slug === slugify(c.name)
                                                ? slugify(e.target.value)
                                                : c.slug,
                                        subdomain:
                                            c.subdomain === '' ||
                                            c.subdomain === slugify(c.name)
                                                ? slugify(e.target.value)
                                                : c.subdomain,
                                    }))
                                }
                                placeholder="Hiring"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="slug">Slug</Label>
                                <Input
                                    id="slug"
                                    className="font-mono"
                                    value={data.slug}
                                    onChange={(e) =>
                                        setData('slug', e.target.value)
                                    }
                                />
                                <InputError message={errors.slug} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="subdomain">Subdomain</Label>
                                <Input
                                    id="subdomain"
                                    className="font-mono"
                                    value={data.subdomain}
                                    onChange={(e) =>
                                        setData('subdomain', e.target.value)
                                    }
                                />
                                <InputError message={errors.subdomain} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="repo_full_name">Repository</Label>
                            <Input
                                id="repo_full_name"
                                className="font-mono"
                                value={data.repo_full_name}
                                onChange={(e) =>
                                    setData('repo_full_name', e.target.value)
                                }
                                placeholder="RnD-Experts-Team/HiringPizza"
                            />
                            <InputError message={errors.repo_full_name} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="default_branch">
                                    Build branch
                                </Label>
                                <Input
                                    id="default_branch"
                                    className="font-mono"
                                    value={data.default_branch}
                                    onChange={(e) =>
                                        setData(
                                            'default_branch',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={errors.default_branch} />
                                <p className="text-xs text-muted-foreground">
                                    Images are built from this branch only.
                                    Nothing is ever built from main.
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="database_name">
                                    Database (optional)
                                </Label>
                                <Input
                                    id="database_name"
                                    className="font-mono"
                                    value={data.database_name}
                                    onChange={(e) =>
                                        setData('database_name', e.target.value)
                                    }
                                    placeholder="hiringpizza"
                                />
                                <InputError message={errors.database_name} />
                                <p className="text-xs text-muted-foreground">
                                    The testing tier appends _testing
                                    automatically.
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Build and health</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label>Build recipe</Label>
                            <Select
                                value={data.build_recipe}
                                onValueChange={(v) =>
                                    setData('build_recipe', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(recipes).map(
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
                            <InputError message={errors.build_recipe} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="health_path">Health path</Label>
                            <Input
                                id="health_path"
                                className="font-mono"
                                value={data.health_path}
                                onChange={(e) =>
                                    setData('health_path', e.target.value)
                                }
                            />
                            <InputError message={errors.health_path} />
                            <p className="text-xs text-muted-foreground">
                                Polled after every swap. A release item that
                                never answers here is a failed item.
                            </p>
                        </div>

                        <div className="flex items-start justify-between gap-4 rounded-lg border border-sidebar-border/60 p-4">
                            <div>
                                <Label htmlFor="per_stack_build">
                                    Build per stack
                                </Label>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    For anything whose client-specific values
                                    are frozen at build time and so cannot share
                                    one image. Next.js inlines its public
                                    environment variables, which is why this
                                    exists.
                                </p>
                            </div>
                            <Switch
                                id="per_stack_build"
                                checked={data.per_stack_build}
                                onCheckedChange={(v) =>
                                    setData('per_stack_build', v)
                                }
                            />
                        </div>
                    </CardContent>
                </Card>

                <div>
                    <Button type="submit" disabled={processing}>
                        Add service
                    </Button>
                </div>
            </form>
        </>
    );
}

ServiceCreate.layout = {
    breadcrumbs: [
        { title: 'Services', href: '/services' },
        { title: 'Add', href: '/services/create' },
    ],
};
