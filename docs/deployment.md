# Deployment and release

How to deploy the API and publish the SDK. The repository contains the
configuration; the accounts, secrets and final publish are manual steps.

Related: [API contract](api-contract.md), [project plan](plan-feature-flags.md),
[ADR 0001](adr/0001-design-decisions.md).

## 1. API on Fly.io

The API is a Docker image (`api/Dockerfile`). `api/fly.toml` sets the process,
the health check and the release command. Migrations run in the release
command, **not** in the container command, so a broken migration stops the
deploy before any Machine takes traffic.

### Prerequisites

- [flyctl](https://fly.io/docs/flyctl/install/) and a Fly.io account.
- A PostgreSQL database. The Fly.io Free plan includes a Managed Postgres
  Developer cluster, but the $5 monthly credit is also used by the Machine. A
  free Neon or Supabase database is a good alternative.
- `openssl` to generate the application secrets.

### Create the database

Create a PostgreSQL database in Neon or Supabase and copy the connection
string. It must look like:

```
postgresql://user:password@host/database?sslmode=require
```

The API accepts it as `DB_URL`; it also reads `DATABASE_URL` because that is
the name Fly.io uses when it attaches a database. URL-encode special characters
in the password (for example `@` as `%40`) and keep `sslmode=require` for a
managed database.

### First deploy

From `api/`:

```bash
fly auth login
fly apps create flagpost-api   # change the name if it is taken
fly secrets set \
  APP_KEY="base64:$(openssl rand -base64 32)" \
  FLAGPOST_ADMIN_KEY="$(openssl rand -hex 32)" \
  DB_URL="postgresql://user:password@host/database?sslmode=require"
fly deploy
```

If the app name in `fly.toml` changes, update `app` and `APP_URL` first. The
release command runs `php artisan migrate --force` on the new image before the
deploy continues.

Verify:

```bash
curl -fsS https://flagpost-api.fly.dev/up
# Application up
fly logs
```

### Create an environment key

Environment keys are created from the CLI and printed only once:

```bash
fly ssh console -C "php artisan flagpost:key:create prod"
# fp_prod_...  <- store it now
```

The database stores only the SHA-256 hash. To rotate, create a new key, deploy
it to the application that uses the SDK, then revoke the old one:

```bash
fly ssh console -C "php artisan flagpost:key:list"
fly ssh console -C "php artisan flagpost:key:revoke <id>"
```

### Management calls

The admin key is the `FLAGPOST_ADMIN_KEY` secret. Fly secrets are write-only:
keep the value somewhere safe when you create it. Example:

```bash
ADMIN_KEY="<the value you set>"
BASE=https://flagpost-api.fly.dev

curl -sS -X POST "$BASE/v1/flags" \
  -H "Authorization: Bearer $ADMIN_KEY" \
  -H "Content-Type: application/json" \
  -d '{"key":"new-checkout","description":"Redesigned checkout flow"}'

curl -sS -X PUT "$BASE/v1/flags/new-checkout/environments/prod" \
  -H "Authorization: Bearer $ADMIN_KEY" \
  -H "Content-Type: application/json" \
  -d '{"enabled":true,"percentage":20,"allowList":["alice"]}'

curl -sS "$BASE/v1/sdk/flags" \
  -H "X-Api-Key: fp_prod_..."
```

### Free plan notes

- The Free plan gives $5 of usage per month once a card is added. A
  `shared-cpu-1x` Machine with 512 MB costs less than that; a Managed Postgres
  Developer cluster costs $4 and shares the same credit. Check the current
  terms before deploying.
- `auto_stop_machines` and `auto_start_machines` keep the demo cheap. The first
  request after a pause has a cold start.
- Free databases have their own limits and may pause when idle. The SDK keeps
  its last good snapshot during an API outage.

### Why not Render's free plan?

Render supports Docker and health checks on the free plan, but
`preDeployCommand` (the release command) requires a paid service. If you use
Render, run `php artisan migrate --force` manually against the production
database before each deploy, or move the service to a paid plan. The Fly.io
configuration in this repository keeps the migration step in the deploy
pipeline.

## 2. Publish `Flagpost.Client` to NuGet

The package is `Flagpost.Client`, multi-targeted for `net8.0` and `net10.0`.
Publishing uses NuGet **Trusted Publishing** with GitHub OIDC: no long-lived
NuGet API key is stored in the repository.

The workflow is `.github/workflows/nuget.yml`. It runs the tests, packs the
package, requests a short-lived API key from nuget.org and pushes the `.nupkg`
and `.snupkg`.

### One-time setup on nuget.org

1. Sign in to [nuget.org](https://www.nuget.org/).
2. Open **Trusted Publishing** and add a policy:
   - Repository owner: `jhonemmir`
   - Repository: `flagpost`
   - Workflow file: `nuget.yml` (the file name only, without
     `.github/workflows/`)
   - Environment: `nuget`
3. Keep the NuGet profile name (not the email) for the next step.

### One-time setup on GitHub

1. Create a repository variable named `NUGET_USER` with the NuGet profile name.
2. Create an environment named `nuget`. Add required reviewers if a publish
   should need an approval. The workflow targets that environment, and the
   nuget.org policy names it, so the names must match.

### Publish a version

Use the **Publish NuGet** workflow from the Actions tab with a version such as
`0.1.0-alpha.1`, or push a tag:

```bash
git tag sdk-v0.1.0-alpha.1
git push origin sdk-v0.1.0-alpha.1
```

The first publish reserves the package id. Use a prerelease version until the
API is stable. The workflow uses `--skip-duplicate`, so a rerun after a partial
failure is safe.

Verify from a clean project:

```bash
dotnet add package Flagpost.Client --prerelease
```

### Pack locally

```bash
dotnet pack sdk-dotnet/src/Flagpost.Client/Flagpost.Client.csproj \
  -c Release -o artifacts
```

`dotnet nuget push` still needs a conventional API key for a local publish;
Trusted Publishing only works from the configured CI workflow.

## 3. Secrets checklist

| Secret | Where | Notes |
|---|---|---|
| `APP_KEY` | Fly secret | Generate once with `php artisan key:generate --show` or `openssl rand -base64 32` |
| `FLAGPOST_ADMIN_KEY` | Fly secret | At least 32 random bytes; keep it out of the repository |
| `DB_URL` | Fly secret | PostgreSQL connection string with `sslmode=require` |
| `NUGET_USER` | GitHub repository variable | NuGet profile name, not a secret |
| Environment keys | Created with `flagpost:key:create` | Printed once; stored only as a hash |

Never commit `.env` files. The API never returns a key after creation and never
logs it; the SDK sends the environment key only in `X-Api-Key`.

## 4. Verification after deployment

```bash
curl -fsS https://flagpost-api.fly.dev/up
curl -sS -o /dev/null -w '%{http_code}\n' https://flagpost-api.fly.dev/v1/sdk/flags
# 401 without X-Api-Key
```

Then create a flag, set its production configuration, fetch the SDK snapshot
with the environment key and call `explain`. The responses must match
[`docs/api-contract.md`](api-contract.md) and the shared vectors.

## Resumen en español

- La API se despliega en Fly.io desde `api/`; `api/fly.toml` define el puerto,
  el health check en `/up` y el *release command* que ejecuta las migraciones
  antes de cambiar el tráfico.
- Los secretos (`APP_KEY`, `FLAGPOST_ADMIN_KEY`, `DB_URL`) se configuran con
  `fly secrets set`, nunca en el repositorio.
- Las llaves de entorno se crean por CLI y se muestran una sola vez.
- El SDK se publica en NuGet con *Trusted Publishing* y OIDC desde
  `.github/workflows/nuget.yml`; no hay una API key permanente de NuGet.
- La primera versión prevista es `0.1.0-alpha.1`; el plan gratuito de Render no
  incluye `preDeployCommand`, por eso la configuración incluida es la de
  Fly.io.
