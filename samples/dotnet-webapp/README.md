# Flagpost sample web app

A minimal ASP.NET Core app that uses `Flagpost.Client` to evaluate the
`new-checkout` flag locally. The page refreshes every two seconds, so a flag
change made through the API appears without restarting the application.

## Run it

From the repository root, the Docker wrapper reads the key from
`~/.config/flagpost/fly.env` when it is available:

```bash
scripts/run-sample.sh
```

Or start it directly with the .NET SDK:

```bash
export Flagpost__ApiKey="fp_prod_..."
dotnet run --project samples/dotnet-webapp
```

Open:

```text
http://localhost:5080/?user=alice
```

The sample defaults to the deployed API at `https://flagpost-api.fly.dev` and
polls every five seconds. The first fetch can take longer while the free API
and database wake up, so the sample allows 30 seconds per request. Override the
values if needed:

```bash
export Flagpost__BaseUrl="http://localhost:8000"
export Flagpost__PollIntervalSeconds="5"
export Flagpost__HttpTimeoutSeconds="30"
```

The environment key is a server-side secret. Do not put it in
`appsettings.json`, a browser, or a mobile app.

## Change the flag

With the admin key from your own environment:

```bash
BASE=https://flagpost-api.fly.dev
ADMIN_KEY="<your admin key>"

# Classic checkout
curl -sS -X PUT "$BASE/v1/flags/new-checkout/environments/prod" \
  -H "Authorization: Bearer $ADMIN_KEY" \
  -H "Content-Type: application/json" \
  -d '{"enabled":false,"percentage":20,"allowList":["alice"]}'

# New checkout
curl -sS -X PUT "$BASE/v1/flags/new-checkout/environments/prod" \
  -H "Authorization: Bearer $ADMIN_KEY" \
  -H "Content-Type: application/json" \
  -d '{"enabled":true,"percentage":20,"allowList":["alice"]}'
```

The sample uses a project reference so it always builds against the current
SDK source. A real application installs the package instead:

```bash
dotnet add package Flagpost.Client --version 1.0.0
```

## What the page shows

- The user id, the deterministic bucket and the reason code.
- Whether the SDK has loaded its first snapshot.
- The active checkout variant, evaluated in process.

The page is intentionally small: no database, no authentication and no custom
frontend framework. It exists to show the SDK and the API contract in motion.
