import { Head, router, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface Project {
    id: number;
    name: string;
    repo_name: string;
    description: string | null;
}

export default function ProjectEdit({ project }: { project: Project }) {
    const { data, setData, put, processing, errors } = useForm({
        name: project.name,
        repo_name: project.repo_name,
        description: project.description ?? '',
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

    return (
        <>
            <Head title={`Edit ${project.name}`} />
            <div className="mx-auto w-full max-w-2xl p-4">
                <h1 className="mb-6 text-2xl font-bold">Edit project</h1>

                <Card className="p-6">
                    <form onSubmit={submit} className="grid gap-5">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Display name</Label>
                            <Input
                                id="name"
                                value={data.name}
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

                        <div className="flex justify-between">
                            <Button type="button" variant="destructive" onClick={destroy}>
                                Delete project
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Save changes
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </>
    );
}

ProjectEdit.layout = {
    breadcrumbs: [{ title: 'Projects', href: '/projects' }],
};
