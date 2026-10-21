# Glossary

**Apply** — On a client's Environment page: recreate the containers that read
a changed value. No build, no release.

**Build** — One image of one service at one commit, numbered per service
("Hiring #143"), with its changelog and migration classification.

**Client** — A customer. Has a slug, a domain, a server and two stacks.

**Control plane** — This application.

**Converge / Adopt** — The two answers to drift: put back what was deployed,
or accept what is running (with a reason).

**Digest** — The `sha256:…` identity of an image's bytes. Tags can be
repointed; a digest cannot. Stacks pin images by digest.

**Drift** — A server running something other than what the control plane
deployed: a different image, or a container that is gone.

**Edition** — A long-lived branch named for a behaviour (`edition/…`), never
for a client, for code that genuinely differs. Shared by every client that
wants that behaviour. Merges flow one way, from `testing` into editions.

**Emergency** — A release straight to live with a typed reason, permanently
marked.

**Entitlement** — Whether a client's stack gets a service: the tick box on the
client page.

**Environment** — A client's values and secrets: vendor credentials, per-tier
overrides, and everything the control plane generates.

**Maintenance window** — The path a destructive migration takes: a scheduled
time, a maintenance page, a backup of the affected tables, then the deploy.

**Pin** — Keep a client out of multi-client updates.

**Process** — One program a service runs beside its web container (queue,
scheduler, nats, reverb). Each is its own container from the same image. The
list is the old `supervisord.conf`.

**Promote** — Deploy to live exactly what the client's testing runs.

**Recipe** — The control plane's default build and run files for a kind of
service: `laravel` or `nextjs`.

**Release** — One deploy, promotion, rollback or emergency to one stack, with
its log and the digests it moved from and to.

**Service** — One deployable unit from one repository: Auth, Hiring, Data,
the Dashboard…

**Service file** — One of a service's build and run files (`Dockerfile`,
`entrypoint.sh`, `.dockerignore`, `compose.service.yml`,
`compose.process.yml`, `nginx.conf`) with its source: default, customised or
from repository.

**Stack** — One client's testing or live tier on a server. Has a compose
project name (`acme_live`) and a port range.

**Tier** — `testing` or `live`.

**Versions** — The board of every client × service: what runs on testing and
live, and how far behind the newest build each is.
