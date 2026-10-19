export type TaskStatus = 'queued' | 'running' | 'succeeded' | 'failed';

export type TaskSummary = {
    id: number;
    type: string;
    title: string;
    status: TaskStatus;
    user: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
};
