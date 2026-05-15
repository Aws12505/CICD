# LCPortal CI/CD Control Plane — Implementation Plan

## Context

Build a self-hosted CI/CD control plane at `cicd.lcportal.cloud`. GitHub webhooks hit one endpoint; the service routes to the correct per-project/per-branch pipeline and executes it. Dashboard shows all runs with live logs, step status, run history, and lets you manage projects + pipelines from the UI — no SSH required after initial setup. Pipeline configs live in the database and are edited from the UI via a visual step builder.

---

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 11 (no starter kit — manual Inertia setup) |
| Frontend | Inertia.js + React + TypeScript + shadcn/ui |
| WebSockets | Laravel Reverb (in worker container via Supervisor) |
| Queue | Laravel Queue — database driver |
| Database | MySQL (external on VPS, same as other projects) |
| Docker | Same pattern as NotificationsPizza: `php:8.4-apache` backend + `php:8.4-cli` worker, both with Node.js added |

---

## Initialization

```bash
# In C:\Projects\
laravel new CICD
# Go through interactive prompts with user — user picks each option
```

The interactive installer will scaffold Inertia + React + TypeScript based on selections. No manual Inertia setup needed.

---

## Architecture

```
GitHub push
  ↓
POST https://cicd.lcportal.cloud/webhook/github
  ↓
WebhookController → validates HMAC (per-pipeline secret)
  ↓
Looks up Pipeline by (repo_name + branch)
  ↓
Creates PipelineRun (status: queued) → dispatches ExecutePipelineJob
  ↓
Worker container (Supervisor) picks up job
  ↓
ExecutePipelineJob runs steps sequentially:
  - proc_open() bash command via mounted /root/core/apps
  - streams stdout/stderr line by line
  - broadcasts PipelineLogLine via Reverb
  - updates PipelineStepResult in DB
  ↓
Final status → pipeline_runs table
```

```
Browser → https://cicd.lcportal.cloud (auth required)
  - /dashboard           → recent runs + project status cards
  - /projects            → list, create, edit projects
  - /projects/{id}       → pipelines for this project
  - /pipelines/{id}/edit → visual step builder
  - /runs                → all runs table
  - /runs/{id}           → run detail: step status + live terminal log + rerun
  ↓
Laravel Echo → Reverb WebSocket (port 8080)
  channel: pipeline.run.{runId}
  events: PipelineLogLine, PipelineRunStatusChanged
```

---

## Database Schema (MySQL)

### `projects`
```
id, name, repo_name (unique — GitHub slug e.g. "b-dashboard-pizza"),
description (nullable), timestamps
```

### `pipelines`
```
id, project_id (FK), branch,
github_secret,
steps (JSON — array of step objects),
enabled (boolean, default true),
timestamps
```
Unique constraint: `(project_id, branch)`

**Step JSON structure:**
```json
{
  "id": "uuid",
  "name": "Build Docker image",
  "command": "docker compose build app",
  "cwd": "/root/core/apps/DashboardPizza",
  "env": { "NODE_ENV": "production" },
  "timeout": 300
}
```

### `pipeline_runs`
```
id, pipeline_id (FK),
status (enum: queued | running | success | failed | cancelled),
commit_sha (nullable), commit_message (nullable), pusher (nullable),
triggered_by (enum: webhook | manual | rerun),
started_at, finished_at (nullable), duration_ms (nullable),
log_path (path to log file in storage),
timestamps
```

### `pipeline_step_results`
```
id, run_id (FK), step_index, name, command,
status (enum: pending | running | success | failed | skipped),
exit_code (nullable), started_at (nullable), finished_at (nullable),
duration_ms (nullable)
```

### `users`
Standard Laravel users table. Seeded with one admin via `AdminUserSeeder`.

### `jobs` / `failed_jobs`
Standard Laravel queue tables.

---

## Files to Create

```
C:\Projects\CICD\
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/LoginController.php   ← simple session auth (no Breeze)
│   │   │   ├── WebhookController.php      ← POST /webhook/github
│   │   │   ├── ProjectController.php      ← CRUD projects
│   │   │   ├── PipelineController.php     ← CRUD pipelines + steps JSON
│   │   │   └── RunController.php          ← list, show, rerun
│   │   └── Middleware/
│   │       └── (Laravel default auth middleware)
│   ├── Jobs/
│   │   └── ExecutePipelineJob.php
│   ├── Models/
│   │   ├── Project.php
│   │   ├── Pipeline.php
│   │   ├── PipelineRun.php
│   │   └── PipelineStepResult.php
│   ├── Services/
│   │   ├── PipelineExecutorService.php    ← proc_open, stream, broadcast
│   │   └── GitHubSignatureService.php     ← HMAC-SHA256 verify
│   └── Events/
│       ├── PipelineLogLine.php
│       └── PipelineRunStatusChanged.php
│
├── resources/
│   ├── js/
│   │   ├── app.tsx                        ← Inertia root (manual setup)
│   │   ├── Pages/
│   │   │   ├── Auth/Login.tsx             ← login page with shadcn
│   │   │   ├── Dashboard.tsx
│   │   │   ├── Projects/
│   │   │   │   ├── Index.tsx
│   │   │   │   ├── Create.tsx
│   │   │   │   └── Edit.tsx
│   │   │   ├── Pipelines/
│   │   │   │   ├── Index.tsx
│   │   │   │   └── Edit.tsx               ← visual step builder
│   │   │   └── Runs/
│   │   │       ├── Index.tsx
│   │   │       └── Show.tsx               ← live log + step status
│   │   └── Components/
│   │       ├── StepBuilder.tsx            ← drag-reorder via @dnd-kit/sortable
│   │       ├── LogViewer.tsx              ← terminal-style, auto-scroll
│   │       └── RunStatusBadge.tsx
│   └── views/
│       └── app.blade.php                  ← Inertia root template (manual)
│
├── routes/
│   ├── web.php                            ← Inertia routes (auth middleware)
│   └── webhook.php                        ← POST /webhook/github (public)
│
├── database/migrations/
│   ├── *_create_projects_table.php
│   ├── *_create_pipelines_table.php
│   ├── *_create_pipeline_runs_table.php
│   └── *_create_pipeline_step_results_table.php
├── database/seeders/
│   └── AdminUserSeeder.php
│
├── Dockerfile                             ← php:8.4-apache + Node.js added
├── Dockerfile.worker                      ← php:8.4-cli + Node.js + Supervisor
├── docker-compose.yml
├── docker-entrypoint-backend.sh           ← same pattern + npm build if needed
├── docker-entrypoint-worker.sh            ← same pattern
├── supervisord-worker.conf                ← queue:work + reverb:start + scheduler
└── .env.example
```

---

## Docker Setup

Follows the NotificationsPizza pattern exactly. Key additions:

**Both Dockerfiles add Node.js 22:**
```dockerfile
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
 && apt-get install -y nodejs \
 && rm -rf /var/lib/apt/lists/*
```

**`docker-entrypoint-backend.sh`** — adds npm build step:
```bash
# Build frontend assets on every container start (production build, not dev)
cd /var/www/html
npm install --no-audit --no-fund
npm run build
```

The Laravel starter kit serves the compiled React assets via Inertia — no separate frontend container or server needed. Apache in the backend container delivers everything.

**`supervisord-worker.conf`** — queue + reverb + scheduler (no NATS):
```ini
[program:laravel-scheduler]
command=/bin/bash -c "while true; do php /var/www/html/artisan schedule:run ...; sleep 60; done"

[program:laravel-worker]
command=php /var/www/html/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --memory=1024
user=root    ← must be root to write to /root/core/apps

[program:laravel-reverb]
command=php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080
user=root
```

**`docker-compose.yml` on VPS:**
```yaml
services:
  backend:
    build: { context: ., dockerfile: Dockerfile }
    container_name: LCPortalCICD_Backend
    ports: ["42069:80"]
    volumes:
      - ./:/var/www/html:delegated
    extra_hosts:
      - "host.docker.internal:host-gateway"   # access VPS MySQL
    restart: unless-stopped

  worker:
    build: { context: ., dockerfile: Dockerfile.worker }
    container_name: LCPortalCICD_Worker
    ports: ["42070:8080"]
    volumes:
      - ./:/var/www/html:delegated
      - /var/run/docker.sock:/var/run/docker.sock   # run docker commands
      - /root/core/apps:/root/core/apps              # access app directories
    extra_hosts:
      - "host.docker.internal:host-gateway"
    restart: unless-stopped
```

**DB_HOST** in `.env` on VPS → `host.docker.internal` (reaches VPS MySQL from inside container).

---

## Auth (No Breeze — Manual)

- `LoginController` with `Auth::attempt()` → redirect to `/dashboard`
- Single login page: `Pages/Auth/Login.tsx` using shadcn `Card`, `Input`, `Button`
- All routes except `/login` and `/webhook/github` protected by `auth` middleware
- `AdminUserSeeder` reads `CICD_ADMIN_EMAIL` + `CICD_ADMIN_PASSWORD` from `.env`
- **Future multi-user**: add `role` enum to `users`, gate admin-only actions

---

## GitHub Webhook Flow

1. `POST /webhook/github` — raw body + `X-Hub-Signature-256` header
2. Extract `repository.name` + branch from `ref`
3. Find `Pipeline` by project `repo_name` + `branch`
4. `GitHubSignatureService::verify($pipeline->github_secret, $rawBody, $header)`
5. If valid: insert `PipelineRun` (queued) → dispatch `ExecutePipelineJob`
6. Return `202 Accepted` immediately

---

## Live Log Streaming

- `proc_open()` with `STDOUT` + `STDERR` as non-blocking pipes
- Read line-by-line in loop with `stream_get_line()`
- Each line: `file_put_contents(log_path, $line, FILE_APPEND)` + broadcast `PipelineLogLine`
- Private channel: `pipeline.run.{runId}` (Reverb)
- Frontend: `Echo.private('pipeline.run.{id}').listen('.PipelineLogLine', e => logEl.append(e.line))`

---

## Nginx on VPS (`cicd.lcportal.cloud`)

```nginx
server {
    listen 443 ssl;
    server_name cicd.lcportal.cloud;

    # Reverb WebSocket
    location ~ ^/(app|apps)/ {
        proxy_pass http://127.0.0.1:42070;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }

    # Laravel app
    location / {
        proxy_pass http://127.0.0.1:42069;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
    }
}
```

---

## Implementation Order

1. `laravel new CICD` (no starter kit, MySQL)
2. Install Inertia + React + TypeScript manually
3. `php artisan install:broadcasting` → Reverb
4. `php artisan queue:table` + `php artisan migrate`
5. Install shadcn/ui + `@dnd-kit/sortable`
6. Migrations + Models + relationships
7. `GitHubSignatureService` + `PipelineExecutorService`
8. Events (`PipelineLogLine`, `PipelineRunStatusChanged`)
9. `ExecutePipelineJob`
10. Controllers + routes (Webhook, Auth/Login, Project, Pipeline, Run)
11. React pages (Dashboard, Projects CRUD, Pipeline Edit with StepBuilder, Runs list, Run Show with LogViewer)
12. `AdminUserSeeder`
13. Docker files (Dockerfile + Dockerfile.worker with Node.js, entrypoints, supervisord.conf, docker-compose.yml)
14. `.env.example`

---

## Verification

1. Locally: `php artisan serve` + `php artisan queue:work` + `php artisan reverb:start` + `npm run dev`
2. Create project + pipeline with a step `echo "hello from pipeline"`
3. `curl -X POST /webhook/github` with correct HMAC — run appears on dashboard
4. Open run detail — live log streams the echo output
5. `docker compose up --build` — both containers start cleanly
6. Deploy to VPS, configure Nginx + Certbot
7. Add GitHub webhook to `b-dashboard-pizza` → push commit → end-to-end run completes
