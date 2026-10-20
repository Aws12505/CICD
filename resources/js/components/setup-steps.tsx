import { Link, router } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Circle, Lock } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { GuideStep, StepStatus } from '@/types/guide';

const ICON: Record<StepStatus, { icon: LucideIcon; className: string }> = {
    done: { icon: CheckCircle2, className: 'size-5 text-emerald-600' },
    blocked: { icon: Lock, className: 'size-5 text-muted-foreground/60' },
    todo: { icon: Circle, className: 'size-5 text-primary' },
};

/** The button a step offers: a link, or one of the few special actions. */
export function StepAction({
    step,
    size = 'sm',
    variant,
}: {
    step: GuideStep;
    size?: 'sm' | 'default' | 'lg';
    variant?: 'default' | 'outline';
}) {
    const tone = variant ?? (step.status === 'todo' ? 'default' : 'outline');

    if (step.action === 'seed-services') {
        return (
            <Button
                size={size}
                variant={tone}
                onClick={() => router.post('/services/seed')}
            >
                {step.cta} <ArrowRight className="size-4" />
            </Button>
        );
    }

    return (
        <Button asChild size={size} variant={tone}>
            <Link href={step.href}>
                {step.cta} <ArrowRight className="size-4" />
            </Link>
        </Button>
    );
}

function StatusIcon({ status }: { status: StepStatus }) {
    const { icon: Icon, className } = ICON[status];

    return <Icon className={className} />;
}

/**
 * A numbered checklist with a button on every step that is open. Used for
 * setting the control plane up and for taking a client live.
 */
export function SetupSteps({
    steps,
    canAct = true,
}: {
    steps: GuideStep[];
    canAct?: boolean;
}) {
    return (
        <ol className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
            {steps.map((step, i) => (
                <li
                    key={step.key}
                    className="flex flex-wrap items-start justify-between gap-3 p-4"
                >
                    <div className="flex min-w-0 gap-3">
                        <div className="pt-0.5">
                            <StatusIcon status={step.status} />
                        </div>
                        <div className="min-w-0">
                            <div
                                className={`font-medium ${step.status === 'blocked' ? 'text-muted-foreground' : ''}`}
                            >
                                {i + 1}. {step.title}
                            </div>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {step.detail}
                            </p>
                        </div>
                    </div>
                    {canAct && step.status !== 'blocked' && (
                        <StepAction step={step} />
                    )}
                </li>
            ))}
        </ol>
    );
}
