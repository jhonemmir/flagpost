# ADR 0001 — Design decisions for flagpost 1.0

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-11-19 |
| Accepted | 2026-11-26 |
| Owners | Project maintainer |
| Scope | v1.0 |
| Related | [Evaluation spec](../evaluation-spec.md), [API contract](../api-contract.md) |

> `flagpost` is the chosen name. The GitHub repository is `jhonemmir/flagpost`, `Flagpost.Client` is published on NuGet, and Packagist has no `flagpost` package; the API is not published to Packagist.

## Context

`flagpost` is a small feature flag service. A PHP API stores flag configuration per environment; a C# SDK downloads that configuration and answers a single question inside an application: "is this feature on for this user?".

It is a portfolio project with a deliberately small scope, but it has to behave like production software: deterministic evaluation, safe failure modes, tests for every ambiguity, and a package installable from NuGet.

The interesting problem is that **two implementations in two languages must agree exactly**. If PHP and C# compute a user's rollout bucket differently, the same user sees different behavior depending on who evaluates. The design therefore puts the algorithm in a written spec and a shared file of test vectors that both languages must pass.

This ADR records the decisions that shape v1.0. They bind the implementation, the tests, the API contract and the README. Anything not listed is out of scope or belongs in the roadmap.

## Decision drivers

1. A scope that can be finished and polished in two to three weeks.
2. The application must keep working when the flag service is down.
3. Identical evaluation results in PHP and C#.
4. Never leak keys or user data.
5. Everything free to host and publish.

## Decisions

| ID | Decision | Choice | Rationale |
|---|---|---|---|
| D1 | Scope | Boolean flags per environment with three rule types: on/off, percentage rollout, allow list. | Small, explainable and useful. |
| D2 | Language | Code, API, README, CHANGELOG and engineering docs in English; Spanish summaries at the end of documents. | The package audience is international. |
| D3 | Where evaluation happens | **In the SDK** (client-side evaluation). The SDK downloads rules and evaluates locally. | Fast, works offline after the first fetch, and user ids never leave the application. |
| D4 | Evaluation algorithm | Ordered steps and reason codes as defined in the evaluation spec. | One written source of truth for both languages. |
| D5 | Bucketing | SHA-256 of `flagKey:userId`, first 4 bytes big-endian unsigned, modulo 100. A user is inside when `bucket < percentage`. | Deterministic, available in both standard libraries, monotonic. |
| D6 | Shared contract | `spec/vectors/evaluation.json` is read by the PHP and the C# test suites. Nobody keeps a copy. Its `vectorsVersion` describes the file format and is independent of the wire `schemaVersion`. | Makes "they agree" a tested fact, without coupling the test format to the protocol. |
| D7 | PHP evaluation | The API implements the algorithm only for the `explain` endpoint. | Debugging aid and the reason the PHP side must honor the contract. |
| D8 | Environments | Fixed in v1: `dev`, `staging`, `prod`. A new flag starts disabled at 100% in all of them. | Removes environment management from scope; "on" is one switch. |
| D9 | Flag key grammar | `^[a-z0-9][a-z0-9._-]{0,63}$`. | Forbids `:`, so the bucket separator is unambiguous. |
| D10 | User ids | Opaque strings used as received: no trimming, case folding or Unicode normalization. Empty equals missing. | Predictable and testable. |
| D11 | Authentication | One admin key from `FLAGPOST_ADMIN_KEY`; several keys per environment, each with an opaque id and a visible prefix. The key itself is stored only as a SHA-256 hash and shown once. | Simple and safe; multiple active keys allow rotation without downtime, and high entropy makes a fast hash adequate. |
| D12 | Secrets and privacy | Keys are never logged or returned after creation. The SDK never sends user ids to the API. Allow lists hold opaque ids, not emails. The SDK response is per-environment (`Vary: X-Api-Key`, `private`) and its allow-list data is capped at 1 MiB per environment. | Allow lists are visible to anyone holding an environment key, and the payload must stay small. |
| D13 | Failure behavior | The SDK never throws from evaluation. Before the first fetch it returns the default (`NOT_READY`); after a failure it keeps the last good snapshot. | A flag outage must not become an application outage. |
| D14 | Sync protocol | `GET /v1/sdk/flags` with `ETag`/`If-None-Match`; polling every 30 s by default with ±20 % jitter, minimum 5 s; honor `Retry-After` and back off on `401`, `403` and `429`. Streaming is out of scope. | Cheap, cache-friendly and easy to reason about; jitter and backoff protect a shared key. |
| D15 | API format | JSON, `/v1` in the path, RFC 9457 problem details for errors, `schemaVersion` in the SDK payload. | Stable, standard, versioned. |
| D16 | API stack | PHP `^8.4`, the latest stable Laravel that supports it (version pinned after verification on Packagist), Pest, PHPStan at max level through Larastan, Pint. | Modern, well-known PHP; Larastan is required for meaningful static analysis of Laravel code. |
| D17 | Data store | SQLite for tests and local work; PostgreSQL for the deployed demo. The CI suite runs against PostgreSQL as well. | Fast tests, and free hosted Postgres exists; a PostgreSQL run catches migration and query differences before deploy. |
| D18 | SDK stack | Multi-target `net8.0` and `net10.0`, `System.Text.Json`, `HttpClient`, Microsoft.Extensions abstractions (options, logging, hosting) at pinned versions. | Minimal dependency surface and a wider audience than a `net10.0`-only package. |
| D19 | Repository layout | Single monorepo: `api/`, `sdk-dotnet/`, `spec/`, `samples/`, `docs/`. | The shared vectors need one source of truth. |
| D20 | Distribution | SDK published to NuGet as `Flagpost.Client`. The API is an application: deployed from a Docker image, not published to Packagist. | Packagist is for libraries; the API is a service. |

**Verified versions (2026-11-23):** PHP 8.4.28, Laravel 13.43.0, Pest 4.7.8, Larastan 3.15.0, Pint 1.32.3 and .NET SDK 10.0.404. `global.json` requests SDK 10.0.100 with `rollForward: latestFeature`; the SDK 10 restores the .NET 8 reference packs from NuGet, so no second SDK is needed. The Microsoft.Extensions packages are still unpinned: they will be added when `FlagClient` is implemented (stage 4).

### Detail: evaluation order (D4)

1. Flag unknown → default (`NOT_FOUND`)
2. Disabled → `false` (`DISABLED`)
3. User in allow list → `true` (`ALLOW_LIST`)
4. Percentage 100 → `true`; percentage 0 → `false`
5. No user id → default (`MISSING_USER_ID`)
6. Compare bucket with percentage

The full table, edge cases and invariants live in the [evaluation spec](../evaluation-spec.md).

### Detail: SDK lifecycle (D13, D14)

- `StartAsync` performs the first fetch with a bounded timeout. If it fails, the SDK still starts and keeps retrying in the background with exponential backoff and jitter.
- The current snapshot is an **immutable object** swapped atomically; evaluation never takes a lock.
- Each snapshot precomputes an ordinal `HashSet<string>` per flag, so allow-list matching is O(1) instead of a scan of up to 1000 entries.
- A response with an unsupported `schemaVersion`, invalid JSON, a field of the wrong type or a non-success status is logged (without the key) and **discarded as a whole**; the previous snapshot stays. A half-applied snapshot is never used.
- `Evaluate` and `IsEnabled` are synchronous and allocation-light, because they run on hot paths.
- A `401`/`403` (revoked key) or `429` is not retried at the normal cadence: the client backs off and honors `Retry-After`, then logs at most once per backoff window to avoid log spam.

### Detail: why client-side evaluation (D3)

| Option | Pros | Cons |
|---|---|---|
| Server-side evaluation (`POST /evaluate` per check) | One implementation; central logic | A network call on every check; user ids sent to the service; outage becomes application outage |
| **Client-side evaluation** | Local and fast; user ids stay in the app; resilient | Algorithm exists in every SDK, so it needs a shared contract |

The cost of the second option is exactly what the spec and shared vectors pay for.

## Consequences

### Positive

- The algorithm is specified once and tested in two languages with the same data.
- The application keeps working with stale or default values when the service is down.
- User identifiers never reach the flag service.
- The scope is small enough to finish and to explain in an interview.

### Negative / trade-offs

- No attribute targeting, no multivariate flags, no experiments. Deferred on purpose.
- Changes reach SDKs only on the next poll (about 30 s by default, plus jitter).
- The modulo step has a negligible bias (buckets 0 to 95 are slightly more likely than 96 to 99). Documented, not corrected.
- Anyone with an environment key can read the allow lists. Allow lists must contain opaque ids.
- Rollouts are per flag and stateless: changing a percentage immediately changes which users are inside (monotonically, never removing someone when it grows).
- Fixed environments limit teams with other naming; custom environments are a roadmap item.
- The allow-list budget (1 MiB per environment) means the per-item maxima cannot be reached at the same time: 1000 ids of 256 bytes fill only a few flags. This is deliberate, so the SDK payload stays small.

### Risks

| Risk | Mitigation |
|---|---|
| PHP and C# drift apart | Shared vectors, a property test against an independent oracle, and a CI job that runs both suites on every change plus a validator for `evaluation.json` |
| Signed vs unsigned integer bug in the hash step | The tests assert the first digest bytes, the decoded unsigned value and the bucket for B01 to B20, so each error is visible in isolation |
| A leaked environment key | Prefix `fp_` for secret scanners, hash-only storage, per-environment scope, several keys per environment so rotation needs no downtime; `flagpost:key:list` and `flagpost:key:revoke` |
| SDK payload grows without bound | 1 MiB per-environment allow-list budget enforced with `422`, plus a contract test at the maximum configuration |
| Revoked key leaves clients polling | Exponential backoff on `401`/`403`, `Retry-After` honored, and one log line per backoff window |
| Free-tier hosting sleeps or changes terms | Document it in the README; keep the API deployable with a plain `docker run` |
| Name `flagpost` is taken | Check before creating the repository; the SDK package id and API name are decoupled from the repo name |
| Overgrowth of scope (dashboard, A/B tests) | Non-goals are listed in the plan and enforced in review |

## Alternatives considered

| Area | Alternative | Why it was rejected |
|---|---|---|
| Evaluation | Server-side only | Network dependency on every check; user ids leave the app |
| Hash | MurmurHash3 | Common in flag systems, but not in either standard library; adds a dependency and a porting risk |
| Hash | Random assignment stored per user | Needs state and storage; not reproducible |
| Rollout | Sticky assignments in the database | Turns a stateless library into a data store |
| Protocol | WebSocket or SSE streaming | PHP-FPM handles long-lived connections poorly; polling with ETag is enough |
| Environments | User-defined | More API surface and edge cases for little value in v1 |
| Auth | Single shared key | Cannot scope read access per environment |
| Auth | Hash keys with bcrypt | Keys are 32 random characters; a slow hash adds cost without adding security |
| API framework | Slim or Symfony | Valid options; Laravel gives migrations, validation and rate limiting out of the box and is widely requested in job postings |
| Layout | Separate repos for API and SDK | Shared vectors would need to be duplicated or published |
| Client | Browser SDK | Keys and allow lists would be exposed to end users |
| Flag type | Strings, numbers and JSON | Complicates the algorithm and the contract |

## Compliance

| Decision | Enforced by |
|---|---|
| D3, D13 | SDK tests with a fake `HttpMessageHandler`: not-ready, failure after success, bad schema, timeout |
| D4, D5, D6 | Contract tests in PHP and C# over `evaluation.json`, asserting `first4BytesHex` and the bucket; property test against an independent oracle; `vectorsVersion` checked separately from `schemaVersion` |
| D7 | `explain` feature tests in the API |
| D8 | Feature test: a new flag exists disabled at 100% in `dev`, `staging` and `prod` |
| D9 | Validation tests for the key grammar, including `:` |
| D10 | Contract cases E13, E19 to E24, E29 and E30 |
| D11 | Feature tests for 401/403; unit test that only the hash is persisted; constant-time comparison; test that two active keys of the same environment both work |
| D12 | Tests that assert keys never appear in logs, responses or errors; tests for `Vary`, `private` and the 1 MiB budget; `SECURITY.md` |
| D14 | Feature tests for `ETag`, `304` (with the cache headers repeated), `If-None-Match` lists and sorted output; SDK tests for the minimum poll interval, jitter, `Retry-After` and `401` backoff |
| D15 | Problem-details tests; JSON schema check of the SDK payload; test that a wrong-typed field discards the whole snapshot |
| D16, D17, D18 | `composer.json`, `*.csproj` with pinned versions; CI restore; Larastan at max level; Pest against SQLite and PostgreSQL; build for `net8.0` and `net10.0` |
| D19 | CI jobs with path filters, plus a job that runs both suites on `spec/**` changes |
| D20 | Release workflow for the SDK; `Dockerfile` and deployment workflow for the API |

## Follow-ups and revisit triggers

- **Attribute targeting:** add it as a new rule type in a minor version, with new spec cases; never by changing existing steps.
- **Algorithm changes:** any change to the hash, separator or modulo is breaking; bump `vectorsVersion` and `schemaVersion` and update this ADR first.
- **Payload size:** if the 1 MiB per-environment budget proves too small, add per-flag limits or gzip before raising it; streaming is still out of scope.
- **Streaming updates:** revisit only if the 30 s polling delay becomes a real complaint.
- **Custom environments:** revisit if `dev`/`staging`/`prod` proves too rigid.
- **Dependency versions:** pin exact versions at project start after verifying them on Packagist and NuGet, and record the date here.

---

## Resumen en español

Este ADR fija 20 decisiones para la v1.0 de `flagpost`:

- **Alcance mínimo:** flags booleanos por entorno con tres reglas: activado o desactivado, porcentaje de usuarios y lista de permitidos.
- **Evaluación en el cliente:** el SDK en C# descarga las reglas y decide localmente. Es rápido, funciona si la API se cae y los identificadores de usuario nunca salen de tu aplicación.
- **Un solo algoritmo, dos lenguajes:** SHA-256 de `flagKey:userId`, primeros 4 bytes como entero sin signo, módulo 100. La spec y un archivo compartido de vectores de prueba obligan a PHP y a C# a dar el mismo resultado.
- **Seguridad:** una llave de administración y varias por entorno (para rotarlas sin corte), de las cuales solo se guarda el hash; ninguna llave se registra ni se devuelve después de crearla. La respuesta del SDK es privada por entorno (`Vary: X-Api-Key`) y su tamaño está acotado.
- **Resiliencia:** el SDK nunca lanza excepciones al evaluar; usa el último estado bueno o el valor por defecto. Un payload inválido se descarta completo y la llave revocada provoca retroceso exponencial, no reintentos cada 30 s.
- **Stack:** PHP 8.4 con Laravel, Pest, Larastan y Pint; SQLite en pruebas y PostgreSQL también en CI y en la demo; SDK multi-target `net8.0`/`net10.0` publicado en NuGet; todo en un solo repositorio.

El nombre `flagpost` está confirmado: el repositorio es `jhonemmir/flagpost`, `Flagpost.Client` está publicado en NuGet y Packagist no tiene un paquete `flagpost`; la API no se publica en Packagist.
