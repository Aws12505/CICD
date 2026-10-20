import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { StatusPill } from '@/components/status-pill';
import { Card } from '@/components/ui/card';
import type { TaskSummary } from '@/types/task';

export default function TasksIndex({ tasks }: { tasks: TaskSummary[] }) {
    return (
        <>
            <Head title="Activity" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    eyebrow="Watch"
                    title="Activity"
                    description="Every server setup, build, release, backup and restore, with its full log. What is running right now is at the top."
                />
                <Card>
                    <div className="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        {tasks.length === 0 && (
                            <p className="p-4 text-sm text-muted-foreground">
                                Nothing has run yet.
                            </p>
                        )}
                        {tasks.map((task) => (
                            <Link
                                key={task.id}
                                href={`/tasks/${task.id}`}
                                className="flex flex-wrap items-center justify-between gap-3 p-4 transition-colors hover:bg-muted/40"
                            >
                                <div>
                                    <div className="font-medium">
                                        {task.title}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {task.type}
                                        {task.user && ` · ${task.user}`}
                                        {task.created_at &&
                                            ` · ${new Date(task.created_at).toLocaleString()}`}
                                    </div>
                                </div>
                                <StatusPill status={task.status} />
                            </Link>
                        ))}
                    </div>
                </Card>
            </div>
        </>
    );
}

TasksIndex.layout = {
    breadcrumbs: [{ title: 'Activity', href: '/tasks' }],
};
