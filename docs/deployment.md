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

- [flyctl](https://fly.io/docs/flyctl/install/) and a Fly.io account. Add a card
  to the personal organization to unlock the $5 of monthly usage; without a
  card a new account only has a one-time $1 credit.
- A PostgreSQL database. For a free demo, use a free Neon or Supabase database
  and keep only the app on Fly.io. A Fly Managed Postgres Developer cluster is
  $4/month and a started 512 MB app Machine is $3.69/month, so both together
  exceed the $5 credit. Check the current prices before deploying.
- `openssl` to generate the application secrets.

All commands below run from `api/`.

### Create the app

```bash
fly auth signup   # or: fly auth login
fly apps create flagpost-api   # change the name if it is taken
```

If the app name in `fly.toml` changes, update `app`, `APP_URL` and the URLs in
this document first.

### Create the database

#### Option A: Neon or Supabase (recommended for the free plan)

Create a PostgreSQL database in Neon or Supabase and copy the connection
string. It must look like:

```
postgresql://user:password@host/database?sslmode=require
```

The API accepts it as `DB_URL`; it also reads `DATABASE_URL` because that is
the name Fly.io uses when it attaches a database. URL-encode special characters
in the password (for example `@` as `%40`) and keep `sslmode=require` for a
managed database.

Use the **direct** Neon connection string (without `-pooler`) as `DB_URL`: the
same secret is used by the release command that runs migrations. Neon's pooled
endpoint is for high connection churn; if you use it, run migrations separately
against the direct endpoint.

#### Option B: Fly Managed Postgres

Requires flyctl v0.5.15 or later (`fly version upgrade`). The Developer plan
costs $4/month, has 20 direct connections and auto-pauses after 15 minutes
without connections; the next connection takes about 15–25 seconds to wake.

```bash
fly mpg create --plan dev-256 --name flagpost-db --region iad
fly mpg list
fly mpg attach <cluster-id> -a flagpost-api
```

`fly mpg attach` sets the `DATABASE_URL` secret on the app, which the API reads
automatically. You do not need a separate `DB_URL` in this option.

### Set the application secrets

Generate the values and store them in a password manager:

```bash
APP_KEY="base64:$(openssl rand -base64 32)"
ADMIN_KEY="$(openssl rand -hex 32)"

fly secrets set APP_KEY="$APP_KEY" FLAGPOST_ADMIN_KEY="$ADMIN_KEY"

# Option A only: the external database connection string.
fly secrets set DB_URL="postgresql://user:password@host/database?sslmode=require"
```

`FLAGPOST_ADMIN_KEY` is the admin credential for every management call. It is
write-only from Fly's side, so keep the value you generated.

### Deploy

```bash
fly deploy --ha=false
```

`--ha=false` creates one Machine instead of the default two, which keeps the
demo inside the free-plan credit. The release command runs
`php artisan migrate --force` on the new image before the Machine takes traffic.
If you already deployed two Machines, run `fly scale count 1`.

Verify:

```bash
fly status
fly checks list
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

- The Free plan gives $5 of usage per month once a card is added. A started
  `shared-cpu-1x` Machine with 512 MB costs $3.69/month when running
  continuously; a Managed Postgres Developer cluster costs $4/month and is
  billed while it exists. The two together are about $7.69/month and exceed
  the credit, so use Neon or Supabase for a free demo. Prices can change.
- `fly deploy` creates two Machines by default. Use `fly deploy --ha=false` to
  create one, or `fly scale count 1` if two were already created.
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
`1.0.0`, or push a tag:

```bash
git tag sdk-v1.0.0
git push origin sdk-v1.0.0
```

The workflow uses `--skip-duplicate`, so a rerun after a partial failure is
safe.

Verify from a clean project:

```bash
dotnet add package Flagpost.Client
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
  antes de cambiar el tráfico. Usa `fly deploy --ha=false` para una sola
  máquina y no crear dos por defecto.
- Para no superar los $5 mensuales, usa Neon o Supabase: una máquina de 512 MB
  cuesta unos $3,69/mes, mientras que un clúster Managed Postgres Developer
  cuesta $4/mes y superaría el crédito junto con la app.
- Los secretos (`APP_KEY`, `FLAGPOST_ADMIN_KEY`, `DB_URL`) se configuran con
  `fly secrets set`, nunca en el repositorio.
- Las llaves de entorno se crean por CLI y se muestran una sola vez.
- El SDK se publica en NuGet con *Trusted Publishing* y OIDC desde
  `.github/workflows/nuget.yml`; no hay una API key permanente de NuGet.
- La versión estable es `1.0.0`; el plan gratuito de Render no incluye
  `preDeployCommand`, por eso la configuración incluida es la de Fly.io.
