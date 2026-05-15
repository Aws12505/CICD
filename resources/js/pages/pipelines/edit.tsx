import { Head, router, useForm } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import type { FormEventHandler} from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import type { PipelineStep} from '@/components/step-builder';
import { makeEmptyStep, StepBuilder } from '@/components/step-builder';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

interface Project {
    id: number;
    name: string;
    repo_name: string;
}

interface Pipeline {
    id: number;
    branch: string;
    enabled: boolean;
    steps: PipelineStep[];
    github_secret: string;
}

interface Props {
    project: Project;
    pipeline: Pipeline | null;
    generatedSecret: string;
}

export default function PipelineEdit({ project, pipeline, generatedSecret }: Props) {
    const isEdit = pipeline !== null;
    const [steps, setSteps] = useState<PipelineStep[]>(
        pipeline?.steps?.length ? pipeline.steps : [makeEmptyStep()],
    );

    const form = useForm<{
        branch: string;
        github_secret: string;
        enabled: boolean;
        steps: PipelineStep[];
    }>({
        branch: pipeline?.branch ?? '',
        github_secret: pipeline?.github_secret ?? generatedSecret,
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

    const webhookUrl = `${window.location.origin}/webhook/github`;

    return (
        <>
            <Head title={isEdit ? `Edit ${pipeline.branch}` : 'New pipeline'} />
            <div className="mx-auto w-full max-w-3xl p-4">
                <h1 className="text-2xl font-bold">
                    {isEdit ? 'Edit pipeline' : 'New pipeline'}
                </h1>
                <p className="mb-6 text-sm text-muted-foreground">
                    {project.name} · <span className="font-mono">{project.repo_name}</span>
                </p>

                <form onSubmit={submit} className="grid gap-6">
                    <Card className="grid gap-5 p-6">
                        <div className="grid gap-2">
                            <Label htmlFor="branch">Branch</Label>
                            <Input
                                id="branch"
                                value={data.branch}
                                className="font-mono"
                                placeholder="master"
                                onChange={(e) => setData('branch', e.target.value)}
                            />
                            <InputError message={errors.branch} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="secret">GitHub webhook secret</Label>
                            <div className="flex gap-2">
                                <Input
                                    id="secret"
                                    value={data.github_secret}
                                    className="font-mono text-xs"
                                    onChange={(e) => setData('github_secret', e.target.value)}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    title="Generate new"
                                    onClick={() =>
                                        setData(
                                            'github_secret',
                                            crypto.randomUUID().replace(/-/g, ''),
                                        )
                                    }
                                >
                                    <RefreshCw className="size-4" />
                                </Button>
                            </div>
                            <InputError message={errors.github_secret} />
                            <p className="text-xs text-muted-foreground">
                                Use this as the <strong>Secret</strong> in the GitHub webhook.
                                Payload URL:{' '}
                                <span className="font-mono">{webhookUrl}</span>
                            </p>
                        </div>

                        <div className="flex items-center gap-3">
                            <Switch
                                checked={data.enabled}
                                onCheckedChange={(v) => setData('enabled', v)}
                            />
                            <Label>Enabled (process incoming webhooks)</Label>
                        </div>
                    </Card>

                    <div>
                        <h2 className="mb-3 font-semibold">Steps</h2>
                        <StepBuilder steps={steps} onChange={setSteps} />
                    </div>

                    <div className="flex justify-between">
                        {isEdit ? (
                            <Button type="button" variant="destructive" onClick={destroy}>
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
