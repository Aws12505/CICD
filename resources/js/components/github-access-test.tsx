import { Loader2, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { csrf } from '@/lib/csrf';

type Result = {
    service: string;
    repository: string;
    branch: string;
    ok: boolean;
    message: string;
};

type Outcome =
    | { state: 'idle' }
    | { state: 'running' }
    | { state: 'no-token' }
    | { state: 'failed'; error: string }
    | { state: 'done'; results: Result[] };

/**
 * Checks the saved GitHub token against every service repository and its
 * build branch, so "the first build failed" turns into which repository and
 * what to change on GitHub.
 */
export function GithubAccessTest() {
    const [outcome, setOutcome] = useState<Outcome>({ state: 'idle' });

    const run = async () => {
        setOutcome({ state: 'running' });

        try {
            const response = await fetch(
                '/control-plane/settings/test-github',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrf(),
                    },
                },
            );

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const data: { configured: boolean; results: Result[] } =
                await response.json();

            setOutcome(
                data.configured
                    ? { state: 'done', results: data.results }
                    : { state: 'no-token' },
            );
        } catch (error) {
            setOutcome({
                state: 'failed',
                error: `The check did not run (${error instanceof Error ? error.message : 'network'}). Try again in a minute.`,
            });
        }
    };

    const failing =
        outcome.state === 'done'
            ? outcome.results.filter((r) => !r.ok).length
            : 0;

    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between gap-3 space-y-0">
                <CardTitle>Can the token read the repositories?</CardTitle>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => void run()}
                    disabled={outcome.state === 'running'}
                >
                    {outcome.state === 'running' ? (
                        <Loader2 className="size-4 animate-spin" />
                    ) : (
                        <ShieldCheck className="size-4" />
                    )}{' '}
                    Test GitHub access
                </Button>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
                <p className="text-muted-foreground">
                    Checks the <b>saved</b> token (save first if you just
                    changed it) against every service's repository and build
                    branch. A build that fails to fetch is almost always this.
                </p>

                {outcome.state === 'no-token' && (
                    <StatusPill tone="warning">
                        No GitHub token is saved yet
                    </StatusPill>
                )}
                {outcome.state === 'failed' && (
                    <StatusPill tone="danger">{outcome.error}</StatusPill>
                )}
                {outcome.state === 'done' && outcome.results.length === 0 && (
                    <p className="text-muted-foreground">
                        There are no services yet. Add them first under
                        Services.
                    </p>
                )}
                {outcome.state === 'done' && outcome.results.length > 0 && (
                    <>
                        <div>
                            {failing === 0 ? (
                                <StatusPill tone="success">
                                    The token can read all{' '}
                                    {outcome.results.length} repositories
                                </StatusPill>
                            ) : (
                                <StatusPill tone="danger">
                                    {failing} of {outcome.results.length} cannot
                                    be read
                                </StatusPill>
                            )}
                        </div>
                        <ul className="grid gap-2">
                            {outcome.results.map((r) => (
                                <li
                                    key={r.repository}
                                    className="grid gap-1 rounded-lg border border-border p-3"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-medium">
                                            {r.service}{' '}
                                            <span className="font-mono text-xs font-normal text-muted-foreground">
                                                {r.repository} · {r.branch}
                                            </span>
                                        </span>
                                        <StatusPill
                                            tone={r.ok ? 'success' : 'danger'}
                                        >
                                            {r.ok ? 'readable' : 'cannot read'}
                                        </StatusPill>
                                    </div>
                                    {!r.ok && (
                                        <p className="text-muted-foreground">
                                            {r.message}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
