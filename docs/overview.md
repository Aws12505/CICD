# Overview

## What this is

We run about twelve services for a client: ten Laravel APIs, a Next.js
dashboard and a mobile app. The same product is sold to more clients, each on
their own VPS, each able to switch services on and off, ask for differences,
and receive updates.

The control plane is this Laravel application. It:

- holds every client, server, stack and setting as database rows;
- builds one image per service version from GitHub and pushes it to the
  registry (`ghcr.io`);
- writes each stack's `docker-compose.yml`, environment and nginx vhosts and
  pushes them to the client's server over SSH;
- deploys, promotes, rolls back, backs up, watches for drift and collects
  metrics;
- never does any of that on its own. A merge only builds. Deploying is a
  click.

## The one rule

**No repository, branch, folder or image ever carries a client's name. Every
difference between clients is data in this application.**

A client that wants a different logo, label, threshold or visible feature gets
a setting. A client that needs genuinely different code gets an **edition**:
a long-lived branch named for the behaviour (`edition/two-step-approval`),
never for the client, shared by every client that wants that behaviour.
Something everyone should have goes into `testing` and reaches everyone.

Image tags carry a version, a commit and sometimes an edition or a stack
number. Never a client.

## How a change reaches a client

```
developer merges into testing ──► webhook ──► control plane builds one image
                                                      │
                                       Versions board shows "n behind"
                                                      │
                        operator clicks Update ──► client's testing stack
                                                      │
                        operator clicks Promote ──► client's live stack
                                                 (same image, by digest)
```

Testing and live run the exact same bytes: the image is addressed by digest,
not by tag. Every client's testing and live move independently. Rolling back
is re-pointing to the digests a previous release recorded.

## Decisions, in plain words

These are settled. The reasons are here so nobody has to rediscover them.

| Decision | Why |
|---|---|
| **Immutable images.** Code is copied into the image at build time; nothing on a client server is built from source or bind-mounted. | Tested bytes are live bytes. A client server never holds our source or a GitHub credential. Rollback is instant. |
| **Build from `testing` only.** Promotion deploys the same digest to live and fast-forwards `main` as a record. | The only model where "it passed testing" means anything. |
| **One image per service, one process per container.** A worker is the same image with a different command. There is no supervisord and no separate worker image. | One build, one digest, one thing to roll back. A crashed worker is a stopped container you can see on the server page, not a process hidden inside another container. |
| **Shared MySQL, Redis and NATS per client server, for both tiers.** Isolation by credentials: per-tier database users, a Redis user for testing that cannot flush, separate stream names. | Matches how it runs today; one model to reason about. |
| **The dashboard is built per stack.** Next.js freezes `NEXT_PUBLIC_*` at build time, so each stack gets its own build of the same commit, tagged with the stack id. | Cheaper than rewriting ~120 route files. |
| **Hard environment validation.** A service declares the keys it needs; a release will not start while one is missing. | No silent fallbacks to our own production URLs. |
| **Migrations are classified at build time.** Drops, renames and raw SQL are destructive and take the maintenance-window path. | Data loss gets a scheduled window, a targeted backup and a maintenance page; everything else deploys live. |
| **The control plane generates server keys.** You paste a one-line command (public key only) into the VPS console. | No person ever handles a private key. |
| **Everything is a button.** Merging builds. Deploying, promoting, rolling back, restoring: each is a click, recorded with who did it. | Nothing moves without someone deciding it should. |
| **The control plane owns default build and run files per service**, editable in the UI, overridable from the repository. | Nothing has to be committed to a product repository before it can be deployed. See [Build and run](build-and-run.md). |

## Who does what

| Role | Can |
|---|---|
| Admin | Everything: servers, settings, terminal, restores, deleting. |
| Operator | Clients, services, builds, releases, environment values. Not servers or settings. |
| Viewer | Read everything, change nothing. |

There is no self-registration. See [Workflows → Give someone access](workflows.md#give-someone-access).
