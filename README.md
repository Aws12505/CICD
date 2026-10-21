# Control plane

One copy of the software, many clients, each on their own server. This Laravel
application builds every service once, holds each client's configuration as
database rows, and deploys to the client servers over SSH when an operator
clicks. Nothing reaches a client automatically.

## Start here

- New to the project? Read [docs/README.md](docs/README.md): what it is, how
  the work flows, how a service becomes a running container, and how to
  install the control plane itself.
- Running it already? Sign in and open **Guide** in the sidebar. The dashboard
  says what to do next.

## The one rule

No repository, branch, folder or image ever carries a client's name. Every
difference between clients is a row in this application's database. See
[docs/overview.md](docs/overview.md).

## Working on the code

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
composer run dev
```

Tests: `php artisan test`. Formatting: `vendor/bin/pint --dirty`. Frontend:
`npm run build`, `npx tsc --noEmit`, `npm run lint`.
