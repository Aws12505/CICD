# Build and run

How a service becomes an image, and how a stack runs that image. Everything
here is visible and editable in the application under the service's **Build &
run files** card; this page explains what you are looking at.

## From repository to image

A **build** takes one commit of one service and produces one image, pushed to
the registry as `ghcr.io/<org>/<service>:<version>-<sha>` and addressed
afterwards by its **digest**. The control plane does the build; client servers
only pull.

Three files take part:

| File | What it does |
|---|---|
| `Dockerfile` | How the image is built. The default for a Laravel service runs `composer install --no-dev` and builds Vite assets (in a stage with PHP and Node, because Wayfinder runs `php artisan`) in build stages, then copies only the application onto the shared PHP base image (below): no Composer, git, Node or compiler reaches the image, and it runs as `www-data`. A service whose catalog has **Build the front end** off (an API only, such as QA) skips the asset step. The Next.js default builds on `node:22-alpine` with `npm ci` (falling back to `npm install`: the repository carries two lockfiles); with `output: "standalone"` in the repository's `next.config` the image holds only the traced server files (about 420 MB instead of 1.2 GB). |
| `entrypoint.sh` (Laravel only) | What runs when a container starts. It caches routes, views and events (never config: it comes from the environment), links storage, and with no arguments serves HTTP with Apache; given a command, it runs that instead. This one switch is how the same image is a web server or a worker. |
| `.dockerignore` | What is left out of the build context (`.git`, `node_modules`, `vendor`, `storage`, `.env`…). |

### Size and tests

Every image is **tested before it is pushed**, and one that fails is never
pushed: a Laravel image must have the PHP extensions the services use, must
not run as root, and must answer its health path (`/up`) within 45 seconds
when started with a throwaway environment and no database; a Next.js image
must answer below 500. The image's size is recorded and shown on the Builds
page, in red above the budget (`DEPLOY_IMAGE_BUDGET_LARAVEL_MB`, 700 by
default; `DEPLOY_IMAGE_BUDGET_NEXTJS_MB`, 400).

| Image | Before | Now |
|---|---|---|
| PHP base | 983 MB | 341 MB |
| Hiring, Data, QA | about 1 GB | about 370 MB |
| Dashboard | about 1.2 GB | 1.2 GB as the repository is; 423 MB with `output: "standalone"` |

### The shared PHP base image

Compiling PHP's extensions takes minutes and is identical for every Laravel
service, so it is done once. The base keeps only the extensions' runtime
libraries: the compilers, headers and toolchain the stock `php:8.4-apache`
carries are removed and the result copied into a single layer. Apache keeps
prefork with mod_php; `APACHE_MAX_WORKERS` (150 by default) and
`PHP_MEMORY_LIMIT` (2G) are read from a service's environment, so they change
with Apply, not a rebuild. The control plane builds `php-base:<hash>` from
`resources/build-recipes/laravel/base.Dockerfile`, pushes it to the registry,
and every service's `Dockerfile` starts `FROM` it. A build checks the registry
first (the **Checking the PHP base image** step) and only builds it when the
tag is missing; the tag is a hash of the file, so it never changes under a
service.

To pick up a newer PHP (security releases), bump the `Base revision` line in
that file and deploy the control plane: the next Laravel build makes the new
base, once, and the services follow on their next builds. Services whose
`Dockerfile` comes from their repository or is customised are not affected
unless they start `FROM ${PHP_BASE}` (an `ARG PHP_BASE` the builder fills in).

The dashboard is built once **per stack** because Next.js freezes its
`NEXT_PUBLIC_*` values at build time. Its tag carries the stack id, never the
client's name.

A build also classifies the commit's migrations. Drops, renames, column
changes and raw SQL make it **destructive**: deploying it needs a maintenance
window and a targeted backup. Everything else is **additive** and deploys live.

## From image to running stack

A **stack** is one client's testing or live tier on a server. The control
plane writes, under `/srv/<client>_<tier>/` on the server:

- `docker-compose.yml`, built from each enabled service's compose blocks;
- one `.env` file per service (`env/<service>.env`), generated;
- `storage/<service>/`, a host directory the containers write uploads and
  caches to (it is what the file backups copy);
- `nginx/<domain>.conf` (TLS) and `nginx/<domain>.http` (plain, used until
  the certificate exists) per service domain.

A service contributes **one web container** plus **one container per
process**, all from the same image:

```yaml
  hiring:                                   # compose.service.yml
    image: ghcr.io/org/hiring@sha256:…
    container_name: acme_live_hiring
    ports: ["127.0.0.1:40003:80"]
    env_file: [env/hiring.env]
    volumes: ["/srv/acme_live/storage/hiring:/var/www/html/storage/app"]

  hiring_queue:                             # compose.process.yml, once per process
    image: ghcr.io/org/hiring@sha256:…      # same image
    container_name: acme_live_hiring_queue
    command: ["sh","-c","php artisan queue:work --sleep=3 --tries=1"]
```

### Processes are the supervisord of old

On the old server each service had a `Dockerfile.worker` with supervisord
running the queue, the scheduler and the NATS consumer inside one container.
Here the service's **Processes** table (service page) is that
`supervisord.conf`: one line per program, each becoming its own container
from the same image, restarted by Docker (`restart: unless-stopped`).

| Service | Processes |
|---|---|
| Data, Hiring, Maintenance, Auth (pizzasys), Toolbox | `scheduler` (`php artisan schedule:work`), `queue` (`php artisan queue:work …`), `nats` (`php artisan nats:consume`) |
| Operations | the same three, with `queue` serving `--queue=humanity,default` (the Humanity sync that used to be an extra supervisord program) |
| QA (AuditApp) | `scheduler` and `nats` |
| Inventory | `nats` only |
| Notifications | `queue`, `nats` and `reverb` (`php artisan reverb:start`), which listens on port 8080 and is proxied under `/app` |
| Screens, Dashboard | none |

A process that listens gets a **port** and a **path** in the table; nginx
proxies that path on the service's domain to the process container. A
crashed worker is a stopped container on the server page and a drift alert,
not a line in a supervisord log.

### Containers on the server

Every container rotates its logs (10 MB × 3), runs under an init, and is given
time to stop: a queue worker gets its job timeout plus ten seconds and stops
on SIGTERM, so it finishes the job in hand. The web container reports its
health from the image; process containers have none (they serve no HTTP). The
shared MySQL, Redis and NATS report their health too, and setting up a server
turns on Docker's own log rotation and a weekly removal of unused images.

### MySQL sized to what runs

One MySQL serves every stack on a server, and **no project is ever limited**.
Instead, the control plane works out the most connections the deployed
services can open at once (every web container's `APACHE_MAX_WORKERS`, one per
process container, scheduled tasks that run side by side, times the
connections one process holds: four for Data) and sets `max_connections` a
quarter above that, plus a reserve. It does this live, without restarting
MySQL, on every server setup, release and Apply, so enabling a service raises
the ceiling before its containers start. The server page shows the ceiling,
how it is made up, and the connections in use; an alert fires at 80 %.

MySQL itself is tuned for many short connections (thread cache, open-files
limit, a buffer pool of about 40 % of the server's RAM, idle connections
closed after ten minutes; Laravel reconnects on its next query).

### nginx and certificates

Each service domain gets a vhost from the service's `nginx.conf` template:
TLS on 443, redirect from 80, `/` proxied to the web container's port, one
`location` per listening process, and the ACME challenge path for certbot.
Until a certificate exists the plain `.http` variant is linked instead; the
deploy requests a certificate for every hostname that resolves and switches
to `.conf`. Renewal is certbot's timer with an nginx reload hook.

During a maintenance window both variants are replaced by a generated page
returning 503, and put back when the window closes, even if the release
failed.

## Changing any of it

Every file above is a row per service with one of three sources:

| Source | Meaning |
|---|---|
| **default** | The control plane's copy, kept in this repository under `resources/build-recipes/<laravel|nextjs>/`. No row in the database. |
| **customised** | Edited in the application. Stored in the database for that service. |
| **from repository** | A path inside the service's repository, used as is at build time. Only for the build files (`Dockerfile`, `entrypoint.sh`, `.dockerignore`). |

Open the service → **Build & run files** → a file. Edit, **Save**. **Reset to
default** deletes the row. **Use a file in the repository** asks for the
path. When the `Dockerfile` comes from the repository, the control plane's
`entrypoint.sh` is not copied into the image and the page says so.

Build files take effect on the next **build**. Compose blocks and `nginx.conf`
take effect on the next **deploy** or **Apply** of each stack. **Preview on a
stack** renders the finished `docker-compose.yml` and vhost for a chosen stack
so an edit can be checked first.

### Placeholders

The compose blocks and `nginx.conf` are templates. `{{name}}` is replaced per
stack; a template is refused on save if it uses anything else.

| File | Placeholders |
|---|---|
| `compose.service.yml` | `{{slug}}` `{{image}}` `{{container}}` `{{host_port}}` `{{container_port}}` `{{env_file}}` `{{storage_host}}` `{{storage_container}}` `{{network}}` |
| `compose.process.yml` | the same, plus `{{name}}` (the process), `{{command}}` (a JSON exec array) and `{{ports_block}}` |
| `nginx.conf` | `{{domain}}` `{{host_port}}` `{{cert_dir}}` `{{acme_location}}` `{{process_locations}}` |

Rules the validator enforces, and why:

- `{{ports_block}}`, `{{acme_location}}` and `{{process_locations}}` expand
  to several lines and must be **alone on their line**. They are re-indented
  to that line and the line disappears when there is nothing to put there.
- A process block must contain `command: {{command}}` exactly, unquoted. It
  expands to `["sh","-c","…"]`; a quoted string would be split on spaces and
  break on quotes.
- Both compose blocks must keep `image: {{image}}` and
  `container_name: {{container}}`. Releases, drift checks and the services'
  own environment all key on those names.
- Write `$$` for a literal dollar in a compose block. Docker compose expands
  `$VAR` in the file on the server; substituted values are escaped for you.

## When a build fails

Open the build under **Builds**; its log streams and is kept.

| Log says | Cause and fix |
|---|---|
| `could not read Username for 'https://github.com'` or `Authentication failed` | The GitHub token cannot read that repository. **Settings → Test GitHub access** says which repository and what to change. |
| `Dockerfile … not found` | The service's `Dockerfile` is set to a repository path that does not exist at that commit. Fix the path or reset to default. |
| `composer install` fails on an extension | The default `Dockerfile` installs the extension set the services use today. A new extension goes into the service's `Dockerfile` (customise it) and the default here if every service needs it. |
| `npm ci` fails on the dashboard | The lockfile does not match `package.json`. The default falls back to `npm install`; the real fix is one lockfile in the repository. |
| `base:` step fails with `installing redis failed` | PECL's server (phpredis) is down for a moment. The base build retries five times and keeps the compiled extensions, so **Build now** again a few minutes later continues quickly. |
| `test:` step fails | The image built but did not pass its test, and was not pushed. The log shows which check and, for the health check, the container's own log: usually a missing extension or a service provider that needs the database at boot. |
| The first Laravel build takes several minutes | It is making the shared PHP base image (see below). Once, then every Laravel service builds in seconds. |
| `denied` on push | The registry push token lacks `write:packages`, or the registry username is not the token's owner. |
