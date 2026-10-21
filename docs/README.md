# Documentation

Read these in order the first time. Each page stands alone afterwards.

| Page | Read it when |
|---|---|
| [Overview](overview.md) | You want to know what this is, the one rule, and why things are the way they are. |
| [Workflows](workflows.md) | You are about to onboard a client, ship an update, change one client, or get into a server. Step by step, with the page to open at each step. |
| [Build and run](build-and-run.md) | You want to know how a service becomes an image and how a stack runs it: Dockerfile, entrypoint, compose, processes, nginx, certificates, and how to change any of them. |
| [Install](install.md) | You are setting up the control plane itself on a fresh server, or it is installed and something (live logs, the queue, GitHub) does not work. |
| [Glossary](glossary.md) | A word on a page is unclear. |

The application has the same content as an interactive **Guide** (sidebar →
Guide) and tells you the next step on the dashboard. These pages exist so the
whole thing can be read without the application running, for example before
you have a server for it.

## The shortest possible version

1. Install the control plane on its own small VPS ([Install](install.md)).
2. Paste the GitHub token, the two registry tokens and the webhook secret into
   **Settings**; click **Add the standard services** under **Services**.
3. For each client: **Servers → Add server** (paste one line into the VPS
   console, Verify, Set up server), **Clients → Add client**, point the
   client's `*.domain` at the server, tick the services on testing, fill the
   client's **Environment**, **Update** testing, test, **Promote** to live.
4. From then on: developers merge into `testing`, that builds an image, and
   **Versions** shows who is behind. **Update** and **Promote** are clicks.
