import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export default function ProjectCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        repo_name: '',
        description: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/projects');
    };

    return (
        <>
            <Head title="New project" />
            <div className="mx-auto w-full max-w-2xl p-4">
                <h1 className="mb-1 text-2xl font-bold">New project</h1>
                <p className="mb-6 text-sm text-muted-foreground">
                    The repository name must exactly match the GitHub repo name (the webhook
                    payload uses it for routing).
                </p>

                <Card className="p-6">
                    <form onSubmit={submit} className="grid gap-5">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Display name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                placeholder="Dashboard Pizza"
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="repo_name">GitHub repo name</Label>
                            <Input
                                id="repo_name"
                                value={data.repo_name}
                                className="font-mono"
                                placeholder="b-dashboard-pizza"
                                onChange={(e) => setData('repo_name', e.target.value)}
                            />
                            <InputError message={errors.repo_name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="description">Description</Label>
                            <Textarea
                                id="description"
                                value={data.description}
                                rows={3}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                            <InputError message={errors.description} />
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button type="submit" disabled={processing}>
                                Create project
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </>
    );
}

ProjectCreate.layout = {
    breadcrumbs: [
        { title: 'Projects', href: '/projects' },
        { title: 'New', href: '/projects/create' },
    ],
};
