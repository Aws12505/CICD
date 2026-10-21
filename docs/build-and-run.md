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
| `Dockerfile` | How the image is built. The default for a Laravel service installs PHP 8.4 with Apache and the extensions the services use, copies the repository in, runs `composer install --no-dev`, builds Vite assets in a stage that has both PHP and Node (Wayfinder runs `php artisan` during the build) and sets the entrypoint. Node never reaches the final image. The default for the Next.js dashboard is a three-stage build (deps → builder → runner) on `node:22-alpine` with `npm ci`, falling back to `npm install` because the repository carries two lockfiles. |
| `entrypoint.sh` (Laravel only) | What runs when a container starts. With no arguments it caches config and routes, links storage, and serves HTTP with Apache. Given a command, it runs that command as `www-data` instead. This one switch is how the same image is a web server or a worker. |
| `.dockerignore` | What is left out of the build context (`.git`, `node_modules`, `vendor`, `storage`, `.env`…). |

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
| `denied` on push | The registry push token lacks `write:packages`, or the registry username is not the token's owner. |
