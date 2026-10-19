import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { LogViewer } from '@/components/log-viewer';
import { Badge } from '@/components/ui/badge';
import type { TaskStatus } from '@/types/task';

const VARIANT: Record<
    TaskStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    queued: 'outline',
    running: 'default',
    succeeded: 'secondary',
    failed: 'destructive',
};

/**
 * A task's live log and status. Reloads the page's props once the task
 * finishes, so whatever it changed (a server's status, a release's items)
 * shows up without a manual refresh.
 */
export function TaskPanel({
    taskId,
    status,
    log,
    reloadOnFinish = true,
}: {
    taskId: number;
    status: TaskStatus;
    log: string;
    reloadOnFinish?: boolean;
}) {
    const [liveStatus, setLiveStatus] = useState<TaskStatus>(status);
    const [synced, setSynced] = useState(status);

    if (synced !== status) {
        setSynced(status);
        setLiveStatus(status);
    }

    const live = liveStatus === 'queued' || liveStatus === 'running';

    useEffect(() => {
        if (!live) {
            return;
        }

        const channel = window.Echo.private(`task.${taskId}`);
        channel.listen('.TaskStatusChanged', (e: { status: TaskStatus }) => {
            setLiveStatus(e.status);

            if (
                reloadOnFinish &&
                (e.status === 'succeeded' || e.status === 'failed')
            ) {
                router.reload();
            }
        });

        return () => {
            channel.stopListening('.TaskStatusChanged');
        };
    }, [taskId, live, reloadOnFinish]);

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
                <Badge variant={VARIANT[liveStatus]}>{liveStatus}</Badge>
                {live && (
                    <span className="text-xs text-muted-foreground">
                        streaming live
                    </span>
                )}
            </div>
            <LogViewer
                channel={`task.${taskId}`}
                event=".TaskLogLine"
                initialLog={log}
                live={live}
            />
        </div>
    );
}
