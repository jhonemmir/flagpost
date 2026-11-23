# flagpost

[![CI](https://github.com/jhonemmir/flagpost/actions/workflows/ci.yml/badge.svg)](https://github.com/jhonemmir/flagpost/actions/workflows/ci.yml)

Feature flags with client-side evaluation: a small PHP API that stores the rules and a C# SDK that evaluates them locally, in process.

> **Status: early implementation.** The [evaluation spec](docs/evaluation-spec.md), the [API contract](docs/api-contract.md) and the shared [test vectors](spec/vectors/evaluation.json) are written and verified. The evaluation core now exists in PHP and C#: both suites pass the same 20 bucket vectors, 30 evaluation cases and property tests. The HTTP endpoints, the SDK poller and the NuGet package are still to come.

`flagpost` is a working name. It must be checked on GitHub, NuGet and Packagist before the first public release.

## What it does

- Define boolean feature flags per environment (`dev`, `staging`, `prod`).
- Three rule types: on/off, percentage rollout and allow list.
- The C# SDK downloads the rules once and evaluates locally. The application keeps working when the service is down, and user ids never leave it.
- An `explain` endpoint answers "why did this user get this result?" with the reason code and the bucket.

## How it works

Evaluation is deterministic:

```
Bucket(flagKey, userId):
    bytes  = UTF-8 of flagKey + ":" + userId
    digest = SHA-256(bytes)
    n      = first 4 bytes of digest as a big-endian unsigned 32-bit integer
    return n mod 100
```

A user is inside the rollout when `bucket < percentage`. Raising the percentage never removes a user, and the same user always lands in the same bucket. The steps and reason codes are normative in the [evaluation spec](docs/evaluation-spec.md); PHP and C# must pass the same [vectors](spec/vectors/evaluation.json).

## Repository layout

```
docs/                  ADR, evaluation spec, API contract, project plan
spec/vectors/          Shared contract vectors (single source of truth)
api/                   Laravel API (PHP 8.4): evaluation core done, endpoints pending
sdk-dotnet/            Flagpost.Client (net8.0 / net10.0): evaluation core done, sync pending
samples/               Example ASP.NET Core application     — planned, stage 7
```

## Documentation

| Document | What it covers |
|---|---|
| [Project plan](docs/plan-feature-flags.md) | Scope, stages, quality bar and definition of done |
| [ADR 0001](docs/adr/0001-design-decisions.md) | The 20 design decisions for v1.0 |
| [Evaluation spec](docs/evaluation-spec.md) | Algorithm, invariants, reason codes and 30 cases |
| [API contract](docs/api-contract.md) | HTTP contract v1, errors, limits and rate limits |

## Local development

Requires Docker with Compose and `openssl`.

```bash
scripts/dev-env.sh            # creates .env with random APP_KEY, admin key and DB password (once)
docker compose up --build     # API at http://localhost:8000, PostgreSQL alongside it
curl http://localhost:8000/up # "Application up"
docker compose run --rm api php artisan migrate --force
docker compose down
```

`.env` is ignored by git. Migrations are deliberately a *release command*, not part of container startup. The API image uses Laravel's built-in server, which is enough for the demo; swap in php-fpm behind a reverse proxy for real traffic.

## Checks

```bash
python3 scripts/check_vectors.py                       # independent JSON/vector validator

cd api
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --no-progress
./vendor/bin/pest

cd ../sdk-dotnet
dotnet build Flagpost.sln -c Release
dotnet test Flagpost.sln -c Release --no-build
```

The PHP and .NET test projects both read `spec/vectors/evaluation.json`; the .NET project copies it into its build output. The same commands run in [CI](.github/workflows/ci.yml), where the PHP suite is also run against PostgreSQL.

The API will eventually ship as a Docker image; the SDK will be published to NuGet as `Flagpost.Client`.

## Security

- Both kinds of key (admin and per environment) are server-side secrets. The SDK is not meant for browser or mobile code.
- Environment keys are stored only as a SHA-256 hash and shown once. Several keys can be active per environment so they can be rotated.
- Allow lists are visible to anyone holding an environment key: store opaque ids, not emails.
- Keys never appear in logs, responses or error bodies.

## License

MIT. See [LICENSE](LICENSE).

---

## Resumen en español

`flagpost` es un servicio de *feature flags* con evaluación en el cliente: una API en PHP guarda las reglas y un SDK en C# las evalúa localmente, sin llamadas de red por cada comprobación.

**Estado:** implementación inicial. La especificación, el contrato HTTP y los vectores compartidos están escritos y verificados. El núcleo de evaluación ya está implementado en PHP y en C#: ambas suites pasan los mismos 20 vectores del hash, 30 casos y pruebas de propiedad. Faltan los endpoints HTTP, el poller del SDK y la publicación.

- **Un solo algoritmo, dos lenguajes:** SHA-256 de `flagKey:userId`, primeros 4 bytes como entero sin signo, módulo 100. Un usuario siempre cae en el mismo grupo y subir el porcentaje nunca le quita la función a nadie.
- **Arranque local:** `scripts/dev-env.sh`, `docker compose up --build` y `docker compose run --rm api php artisan migrate --force`. El `docker-compose.yml` levanta la API y PostgreSQL; las migraciones son un *release command*, no un paso del arranque.
- **Resiliencia:** si la API falla, el SDK usa el último estado bueno; antes de la primera descarga devuelve el valor por defecto. Nunca lanza excepciones al evaluar.
- **Seguridad:** llaves de servidor, guardadas solo como hash y nunca registradas; las listas de permitidos contienen identificadores opacos.
- **Documentación:** [plan](docs/plan-feature-flags.md), [ADR](docs/adr/0001-design-decisions.md), [especificación](docs/evaluation-spec.md) y [contrato](docs/api-contract.md).

El nombre `flagpost` es provisional y hay que comprobarlo antes de publicar.
