# Install the control plane

The control plane holds root access to every client server. It runs alone on a
small VPS of its own, reachable only from where operators work.

## 1. The server

- Ubuntu 24.04, 2 vCPU, 4 GB RAM, 40 GB disk, a public IPv4.
- A DNS name for it (`cp.example.com` below) with an A record to its IP.
- Docker: `curl -fsSL https://get.docker.com | sh`.
- In the VPS firewall: 22 from your addresses, 80 and 443 from your office
  addresses. Every client server accepts SSH only from this machine's IP.

## 2. The application

```bash
git clone <this repository> /opt/control-plane && cd /opt/control-plane
cp .env.example .env
```

In `.env`:

| Key | Value |
|---|---|
| `APP_URL` | `https://cp.example.com`. Exactly the address you open it at. |
| `APP_KEY` | Generated once with `php artisan key:generate`. **Back it up off the machine**: every stored secret and SSH key is encrypted with it, and the boot script never regenerates a key that is set. |
| `DB_*` | The database (the compose file provides MySQL). |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | `cp.example.com` / `443` / `https`. `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` are generated at first boot when empty. |
| `TERMINAL_GATEWAY_SECRET` | 32+ random characters (`openssl rand -hex 32`), for the browser terminal. |
| `ADMIN_PASSWORD` | The first admin's password, read by the seeder below. |

Then:

```bash
docker compose up -d --build
docker exec CICD php artisan migrate --force
docker exec CICD php artisan db:seed --class=AdminUserSeeder
```

The `CICD` container serves the application; `CICD_Worker` runs the queue
workers and the scheduler (metrics every minute, drift every five, backups
nightly). The terminal gateway is a small Node service in the same compose.

## 3. nginx in front

Copy `nginx/control-plane.conf.example` to `/etc/nginx/sites-available/`,
replace the domain, get a certificate (`certbot --nginx -d cp.example.com`),
then `sudo nginx -t && sudo systemctl reload nginx`.

Three paths matter besides `/`:

| Path | Goes to | Why |
|---|---|---|
| `/app` | Reverb on `127.0.0.1:42070` | The browser's websocket for live logs. |
| `/apps` | Reverb on `127.0.0.1:42070` | The server publishing events. Without it, logs stay "queued" in the browser. |
| `/terminal/ws` | The terminal gateway | The browser terminal. |

## 4. Connect GitHub and the registry

Sign in as the admin. **Settings** (admins only) holds four values; the Guide
in the application walks through each on GitHub's side.

| Value | Where it comes from |
|---|---|
| GitHub token | A **fine-grained** token owned by the organisation, with Contents: read and Pull requests: read/write on every service repository. **Test GitHub access** on the Settings page checks every repository. |
| Registry push token | A **classic** token with `write:packages`. Stays on the control plane. |
| Registry pull token | A **classic** token with only `read:packages`. Installed on every client server, so it must not be able to push. |
| Registry username | The GitHub account that owns the tokens. |
| Webhook secret | `openssl rand -hex 32`. The page then shows the payload URL for an organisation webhook (push events, `application/json`). |

Then **Services → Add the standard services**. Check Auth (pizzasys) and QA
(AuditApp): they build from `events_testing` and record on `events`.

## 5. Check it works

**Settings → System health** says whether the queue, the scheduler and Reverb
are working and shows the command to run for any that is not. **Test live logs
end to end** publishes a real event through Reverb to the page.

| Symptom | Fix |
|---|---|
| The first deploy stops at Auth with "no `app:create-admin` command" (or `service-client:upsert`) | The Auth image was built from a pizzasys branch without the two control-plane commands. Merge `feat/control-plane-commands` into pizzasys's build branch (`events_testing`), push, **Build now** for Auth, then run the release again. |
| A build or release log ends with "has been attempted too many times" although it finished | The queue's `retry_after` is shorter than the job. The default is 7800 seconds, longer than any job; remove `DB_QUEUE_RETRY_AFTER` from `.env` if it is set lower, then `docker compose up -d`. |
| A task stays "queued" | Nothing is taking jobs. `docker exec CICD_Worker supervisorctl status` must show the workers RUNNING. `docker exec CICD_Worker tail -n 60 /var/log/supervisor/worker.log` says why not. `docker restart CICD_Worker`. |
| Live log lines do not appear | `/apps` is missing from nginx, or `APP_URL`/`REVERB_HOST` differ from the address in the browser. After changing them: `docker exec CICD sh -c 'npm run build'` then `docker compose restart` (the Reverb key is baked into the frontend). A working websocket answers `101 Switching Protocols` to a `curl -i -N` with the upgrade headers against `/app/<REVERB_APP_KEY>?protocol=7`. |
| A build fails on the clone | The GitHub token. **Settings → Test GitHub access**; `php artisan github:check` from the container says the same. |
| Failed jobs | `docker exec CICD php artisan queue:failed`, then `queue:retry all` once fixed. |

## 6. Updating the control plane

```bash
cd /opt/control-plane && git pull
docker compose up -d --build
docker exec CICD php artisan migrate --force
```

The boot script rebuilds the frontend and never touches a set `APP_KEY`.
