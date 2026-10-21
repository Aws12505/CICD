# Workflows

Every section names the page to open. The same steps are in the application
under **Guide**, where the links are clickable, and the dashboard always says
which step is next.

Before any of this the control plane itself must be installed and connected to
GitHub: see [Install](install.md).

---

## Onboard a new client

### 1. Add and set up their server

1. Ask for (or create) a VPS: Ubuntu 24.04 LTS (22.04 or Debian 12 also
   work), 4 vCPU, 8 GB RAM, 80 GB SSD or more, a public IPv4, and access to
   the provider's **web console** as root. Nothing needs installing by hand.
2. **Servers → Add server**: a name, the server's **IP address** as the
   address to connect to (not a domain: SSH needs no DNS, and a domain that
   points elsewhere would reach the wrong machine), user `root`, port 22.
3. On the server page, copy the line under step 1. Open the provider's web
   console (Hetzner: Console; DigitalOcean: Access → Launch Console; Contabo:
   VNC), log in as root, paste it, press Enter. It only adds the control
   plane's public key.
4. Click **Verify**. It pins the server's host key, logs in and lists what is
   installed. "Could not log in" means the line was not pasted as root.
5. Click **Set up server**. Docker, nginx, certbot and the firewall are
   installed, then the shared MySQL, Redis and NATS start. It takes a few
   minutes and streams its log. Safe to run again.

Afterwards SSH on that server is allowed only from the control plane. The
provider console still works.

### 2. Point the client's domain

1. Decide the domain, e.g. `acme.cloud`. Every service lives under it:
   `hiring.acme.cloud` for live, `hiringtesting.acme.cloud` for testing, the
   dashboard at `app.acme.cloud`.
2. At the DNS host add an **A record** named `*` pointing at the server's IP
   (and `@` too if the bare domain should resolve). TTL 300.
3. Check with `nslookup hiringtesting.acme.cloud`. DNS can take up to an hour.

Certificates are requested on each deploy for every hostname that resolves and
renewed automatically. A hostname that does not resolve yet stays on plain
HTTP and is retried next time.

### 3. Add the client and choose services

1. **Clients → Add client**: name, a short slug (lowercase letters, digits,
   dashes; it becomes database and container names), the domain, the server,
   and the first admin's email and name.
2. Both stacks (testing and live) are created. On the client page tick the
   services for **testing** first. Auth (pizzasys) is always needed: every
   other service checks users against it.
3. Leave **edition** empty unless developers have made an `edition/…` branch
   for behaviour this client needs. Then pick it, for that service only.
4. Tick the same services on **live** before the first promotion.

### 4. Fill in the client's settings

1. Client page → **Environment**. The two cards at the top say what is still
   missing per tier. A release will not start while anything required is
   missing.
2. **Both tiers** is where most values go: Humanity or TCP logins, LiveKit
   keys for Screens, mail settings. Secrets show "saved" and are never shown
   again; leave a field empty to keep the saved value.
3. **Testing only** / **Live only** override one tier, e.g. `TCP_DRIVER=fake`
   on testing and `tcp` on live.
4. Passwords, `APP_KEY`s, service tokens, database names, URLs and NATS
   settings are generated. "Values worked out by the control plane" under a
   service shows them.
5. **Save**, then **Apply** on a tier that is already running: containers
   that read the changed values are recreated. No build.

### 5. Deploy testing, then promote to live

1. Make sure every enabled service has a build: **Builds** → **Build now** if
   no merge has happened yet.
2. Client page → **Update** on the testing stack. Each service shows what it
   runs and the newest build; untick one to hold it back, or pick an older
   build. **Run**. The log streams on the release page.
3. The first deploy also creates the databases, registers every service with
   Auth, creates the first admin (password: client page → Show passwords,
   admins only) and requests certificates.
4. Test on the `…testing.` hostnames. Then client page → **Promote** on the
   live stack → **Run**. Live gets exactly the images testing ran.
5. A build that drops or rewrites data asks for an agreed time instead of
   running now: a maintenance page goes up, the affected tables are backed up,
   and it starts itself at that time.

Emergency (straight to live, typed reason, permanently marked) is behind the
Promote screen. **Roll back** from any finished release takes seconds.

---

## Ship an update

1. A developer merges into `testing` (pizzasys and AuditApp: `events_testing`).
   GitHub calls the webhook and the control plane builds once: "Hiring #143 ·
   a1b2c3d · additive · 4 commits". Nothing deploys. **Builds → Build now**
   exists for any service and branch.
2. **Versions** shows every client × service: the build on testing, on live,
   and "n behind".
3. For one client: client page → **Update** on testing → Run. Test. →
   **Promote** on live → Run.
4. For several clients: tick them on **Versions** → **Update testing** or
   **Promote to live** at the top. Pinned clients and anything needing a
   maintenance window are skipped and listed; plan those from the client page.
5. **Pin** a client on Versions to keep them out of multi-client updates
   until unpinned.
6. Editions: merging `testing` into `edition/*` is the **Sync edition** button
   on the service page. A clean merge lands and builds; a conflict opens a
   pull request against the edition and that edition's deploys wait. An
   edition is never merged back; promoting its feature to the product is a
   fresh pull request onto `testing`.

---

## Change one client

| They want… | Where | What happens |
|---|---|---|
| A service added or removed | Client page, the tick box | Part of their next testing update; first-run steps run for a new service. |
| A service on an edition | Client page, the edition dropdown | Their next update pulls that edition's build for that service. |
| A credential or value changed | Client → **Environment**, then **Apply** | Affected containers restart. No build. |
| To be frozen | **Versions** → Pin | Excluded from multi-client updates. |
| Something genuinely new | Decide: a value (setting), a different operating model (edition), or missing from the product (goes into `testing`, everyone gets it). | |

---

## Get into a server

1. **Servers →** the server: live CPU, memory, disk and load refreshed every
   five seconds, 24-hour charts, and every container with the build it runs.
2. **Logs** on a container shows its last lines; **Restart** restarts it;
   **Shell** opens a terminal inside it.
3. **Terminal** at the top opens a root shell on the server in the browser
   (admins). Every session is recorded and listed under **Recordings**.
4. **Quick actions**: test and reload nginx, renew certificates, prune old
   images, restart the shared MySQL/Redis/NATS. Each streams to **Activity**.

A stopped worker container, drift (a container running something other than
what was deployed) and a stale backup all show as alerts on the dashboard and
under **Operations**. Drift offers **Converge** (put back what was deployed)
or **Adopt** (accept what is running, with a reason).

---

## Back up and restore

1. Client page → **Backups** on a stack: every backup, its size and whether it
   succeeded. **Back up databases now** / **files now** for an immediate one.
   Databases run nightly, files weekly, both pulled to the control plane.
2. A stack with no successful backup in a day shows an alert.
3. **Restore** (admins): type the stack's name and a reason. A fresh backup of
   the current state is taken first, then the chosen backup is loaded.
   Everything written since it is lost, which is why it asks.

---

## Change how a service is built or run

Service page → **Build & run files**. See [Build and run](build-and-run.md)
for what each file is. In short: open a file, edit, **Save**; **Reset to
default** puts it back; a build file can instead be taken from the repository.
Build files take effect on the next build; compose blocks and the nginx vhost
on the next deploy or **Apply**. **Preview on a stack** shows the result
before anything is deployed.

---

## Give someone access

Three roles: **admin** (everything), **operator** (clients, services, builds,
releases; not servers or settings), **viewer** (read only). Registration is
switched off. An admin creates an account on the control plane server:

```bash
docker exec -it CICD php artisan tinker
```

```php
User::create(['name' => 'Name', 'email' => 'name@example.com', 'password' => bcrypt('a-long-password')])->assignRole('operator');
```
