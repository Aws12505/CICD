import { Head } from '@inertiajs/react';
import { TaskPanel } from '@/components/task-panel';
import type { TaskSummary } from '@/types/task';

export default function TaskShow({
    task,
    log,
}: {
    task: TaskSummary & { error: string | null };
    log: string;
}) {
    return (
        <>
            <Head title={task.title} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <p className="text-xs tracking-[0.2em] text-muted-foreground uppercase">
                        {task.type}
                    </p>
                    <h1 className="font-display text-3xl font-semibold">
                        {task.title}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {task.user ? `Started by ${task.user}` : 'Scheduled'}
                        {task.started_at &&
                            ` · ${new Date(task.started_at).toLocaleString()}`}
                    </p>
                </div>
                <TaskPanel taskId={task.id} status={task.status} log={log} />
            </div>
        </>
    );
}

TaskShow.layout = {
    breadcrumbs: [{ title: 'Activity', href: '/tasks' }],
};
