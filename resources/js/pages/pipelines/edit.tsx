import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import type { PipelineStep } from '@/components/step-builder';
import { makeEmptyStep, StepBuilder } from '@/components/step-builder';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

interface Project {
    id: number;
    name: string;
    repo_name: string;
    repo_full_name: string | null;
}

interface Pipeline {
    id: number;
    branch: string;
    enabled: boolean;
    steps: PipelineStep[];
}

interface Props {
    project: Project;
    pipeline: Pipeline | null;
}

export default function PipelineEdit({ project, pipeline }: Props) {
    const isEdit = pipeline !== null;
    const [steps, setSteps] = useState<PipelineStep[]>(
        pipeline?.steps?.length ? pipeline.steps : [makeEmptyStep()],
    );

    const form = useForm<{
        branch: string;
        enabled: boolean;
        steps: PipelineStep[];
    }>({
        branch: pipeline?.branch ?? '',
        enabled: pipeline?.enabled ?? true,
        steps,
    });
    const { data, setData, processing, errors } = form;

    form.transform((d) => ({ ...d, steps }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (isEdit) {
            form.put(`/pipelines/${pipeline.id}`);
        } else {
            form.post(`/projects/${project.id}/pipelines`);
        }
    };

    const destroy = () => {
        if (pipeline && confirm('Delete this pipeline?')) {
            router.delete(`/pipelines/${pipeline.id}`);
        }
    };

    const repoFullName = project.repo_full_name || project.repo_name;

    return (
        <>
            <Head title={isEdit ? `Edit ${pipeline.branch}` : 'New pipeline'} />
            <div className="mx-auto w-full max-w-4xl p-4">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-semibold">
                            {isEdit ? 'Edit pipeline' : 'New pipeline'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {project.name} ·{' '}
                            <span className="font-mono">{repoFullName}</span>
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">branch-based</Badge>
                        <Badge variant="secondary">webhook: push</Badge>
                    </div>
                </div>

                <form onSubmit={submit} className="grid gap-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Pipeline details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="branch">Branch</Label>
                                <Input
                                    id="branch"
                                    value={data.branch}
                                    className="font-mono"
                                    placeholder="main"
                                    onChange={(e) =>
                                        setData('branch', e.target.value)
                                    }
                                />
                                <InputError message={errors.branch} />
                            </div>

                            <div className="flex items-center gap-3">
                                <Switch
                                    checked={data.enabled}
                                    onCheckedChange={(v) =>
                                        setData('enabled', v)
                                    }
                                />
                                <Label>
                                    Enabled (process incoming webhooks)
                                </Label>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Webhook setup</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm text-muted-foreground">
                            <p>
                                Webhooks are configured at the project level.
                                Use the project secret and payload URL for every
                                branch pipeline.
                            </p>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/projects/${project.id}/edit`}>
                                    Open project settings
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>

                    <div>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="font-semibold">Steps</h2>
                            <Badge variant="secondary">
                                {steps.length} steps
                            </Badge>
                        </div>
                        <StepBuilder steps={steps} onChange={setSteps} />
                    </div>

                    <div className="flex justify-between">
                        {isEdit ? (
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={destroy}
                            >
                                Delete pipeline
                            </Button>
                        ) : (
                            <span />
                        )}
                        <Button type="submit" disabled={processing}>
                            {isEdit ? 'Save pipeline' : 'Create pipeline'}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

PipelineEdit.layout = {
    breadcrumbs: [{ title: 'Projects', href: '/projects' }],
};
