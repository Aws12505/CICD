export type StepStatus = 'todo' | 'done' | 'blocked';

export type GuideStep = {
    key: string;
    title: string;
    detail: string;
    status: StepStatus;
    href: string;
    cta: string;
    /** A special action instead of a link, e.g. "seed-services". */
    action: string | null;
    /** Set on the global "next" step: "Setup" or a client's name. */
    scope?: string;
};

export type GuideClient = {
    id: number;
    name: string;
    slug: string;
    domain: string;
    done: number;
    total: number;
    next: GuideStep | null;
    behind: number;
};
