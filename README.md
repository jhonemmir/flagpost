# flagpost

Feature flags with client-side evaluation: a small PHP API that stores the rules and a C# SDK that evaluates them locally, in process.

> **Status: design.** The [evaluation spec](docs/evaluation-spec.md), the [API contract](docs/api-contract.md) and the shared [test vectors](spec/vectors/evaluation.json) are written and verified (20 bucket vectors and 30 evaluation cases). The API and the SDK are the next milestones; nothing is published yet.

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
api/                   Laravel API (PHP 8.4)                — planned, stage 1
sdk-dotnet/            Flagpost.Client (net8.0 / net10.0)   — planned, stage 4
samples/               Example ASP.NET Core application     — planned, stage 7
```

## Documentation

| Document | What it covers |
|---|---|
| [Project plan](docs/plan-feature-flags.md) | Scope, stages, quality bar and definition of done |
| [ADR 0001](docs/adr/0001-design-decisions.md) | The 20 design decisions for v1.0 |
| [Evaluation spec](docs/evaluation-spec.md) | Algorithm, invariants, reason codes and 30 cases |
| [API contract](docs/api-contract.md) | HTTP contract v1, errors, limits and rate limits |

## Quick start

There is no runnable code yet. The first milestone is the evaluation core, tested only with the shared vectors. Once the API exists, the intended flow is:

```bash
docker compose up
# create an environment key, then a flag, and poll it from the SDK
```

The API will ship as a Docker image; the SDK will be published to NuGet as `Flagpost.Client`.

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

**Estado:** fase de diseño. La especificación, el contrato HTTP y los vectores compartidos están escritos y verificados (20 vectores del hash y 30 casos). La API y el SDK son los siguientes hitos; todavía no hay nada publicado.

- **Un solo algoritmo, dos lenguajes:** SHA-256 de `flagKey:userId`, primeros 4 bytes como entero sin signo, módulo 100. Un usuario siempre cae en el mismo grupo y subir el porcentaje nunca le quita la función a nadie.
- **Resiliencia:** si la API falla, el SDK usa el último estado bueno; antes de la primera descarga devuelve el valor por defecto. Nunca lanza excepciones al evaluar.
- **Seguridad:** llaves de servidor, guardadas solo como hash y nunca registradas; las listas de permitidos contienen identificadores opacos.
- **Documentación:** [plan](docs/plan-feature-flags.md), [ADR](docs/adr/0001-design-decisions.md), [especificación](docs/evaluation-spec.md) y [contrato](docs/api-contract.md).

El nombre `flagpost` es provisional y hay que comprobarlo antes de publicar.
