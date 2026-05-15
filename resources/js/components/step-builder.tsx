import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export interface PipelineStep {
    id: string;
    name: string;
    command: string;
    cwd: string;
    timeout: number;
    env: Record<string, string>;
}

export function makeEmptyStep(): PipelineStep {
    return {
        id: crypto.randomUUID(),
        name: '',
        command: '',
        cwd: '',
        timeout: 600,
        env: {},
    };
}

interface StepBuilderProps {
    steps: PipelineStep[];
    onChange: (steps: PipelineStep[]) => void;
}

export function StepBuilder({ steps, onChange }: StepBuilderProps) {
    const update = (index: number, patch: Partial<PipelineStep>) => {
        onChange(steps.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    };

    const remove = (index: number) => {
        onChange(steps.filter((_, i) => i !== index));
    };

    const move = (index: number, dir: -1 | 1) => {
        const target = index + dir;

        if (target < 0 || target >= steps.length) {
return;
}

        const next = [...steps];
        [next[index], next[target]] = [next[target], next[index]];
        onChange(next);
    };

    const add = () => onChange([...steps, makeEmptyStep()]);

    const envToText = (env: Record<string, string>) =>
        Object.entries(env)
            .map(([k, v]) => `${k}=${v}`)
            .join('\n');

    const textToEnv = (text: string): Record<string, string> => {
        const out: Record<string, string> = {};
        text.split('\n').forEach((line) => {
            const trimmed = line.trim();

            if (!trimmed) {
return;
}

            const eq = trimmed.indexOf('=');

            if (eq === -1) {
return;
}

            out[trimmed.slice(0, eq).trim()] = trimmed.slice(eq + 1).trim();
        });

        return out;
    };

    return (
        <div className="space-y-4">
            {steps.map((step, index) => (
                <div
                    key={step.id}
                    className="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
                >
                    <div className="mb-3 flex items-center justify-between">
                        <span className="text-sm font-semibold text-muted-foreground">
                            Step {index + 1}
                        </span>
                        <div className="flex gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={index === 0}
                                onClick={() => move(index, -1)}
                            >
                                <ArrowUp className="size-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={index === steps.length - 1}
                                onClick={() => move(index, 1)}
                            >
                                <ArrowDown className="size-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={() => remove(index)}
                            >
                                <Trash2 className="size-4 text-red-500" />
                            </Button>
                        </div>
                    </div>

                    <div className="grid gap-4">
                        <div className="grid gap-2">
                            <Label>Name</Label>
                            <Input
                                value={step.name}
                                placeholder="Build Docker image"
                                onChange={(e) => update(index, { name: e.target.value })}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label>Command</Label>
                            <Textarea
                                value={step.command}
                                rows={3}
                                className="font-mono text-xs"
                                placeholder="docker compose -p MyApp build app"
                                onChange={(e) => update(index, { command: e.target.value })}
                            />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label>Working directory</Label>
                                <Input
                                    value={step.cwd}
                                    className="font-mono text-xs"
                                    placeholder="/root/core/apps/MyApp"
                                    onChange={(e) => update(index, { cwd: e.target.value })}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label>Timeout (seconds, 0 = none)</Label>
                                <Input
                                    type="number"
                                    value={step.timeout}
                                    min={0}
                                    onChange={(e) =>
                                        update(index, { timeout: Number(e.target.value) })
                                    }
                                />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label>Environment variables (KEY=value, one per line)</Label>
                            <Textarea
                                value={envToText(step.env)}
                                rows={2}
                                className="font-mono text-xs"
                                placeholder="NODE_ENV=production"
                                onChange={(e) =>
                                    update(index, { env: textToEnv(e.target.value) })
                                }
                            />
                        </div>
                    </div>
                </div>
            ))}

            <Button type="button" variant="outline" onClick={add} className="w-full">
                <Plus className="size-4" /> Add step
            </Button>
        </div>
    );
}
