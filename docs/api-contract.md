# API contract — flagpost 1.0

The HTTP contract between the PHP API, the C# SDK and any other client. The API is versioned in the path (`/v1`). Within `v1`, changes are additive only.

Related: [ADR 0001](adr/0001-design-decisions.md), [Evaluation spec](evaluation-spec.md).

## 1. Conventions

- JSON only. Requests with a body must send `Content-Type: application/json`.
- Field names are `camelCase`. Timestamps are ISO 8601 in UTC (`2026-11-19T15:04:05Z`).
- Errors use **RFC 9457 problem details** (`application/problem+json`).
- No endpoint ever returns or logs the **value of a key** after its creation.
- Environments are fixed in v1: `dev`, `staging`, `prod`.

## 2. Authentication

| Credential | Header | Grants access to |
|---|---|---|
| **Admin key** | `Authorization: Bearer <admin key>` | Management endpoints (section 4) |
| **Environment key** | `X-Api-Key: <environment key>` | `GET /v1/sdk/flags` for its own environment only |

- The admin key comes from the `FLAGPOST_ADMIN_KEY` environment variable (at least 32 random bytes) and is compared in constant time.
- Environment keys look like `fp_prod_<32 random characters>` (base62, about 190 bits). The prefix lets secret scanners recognize them. The key itself is stored only as a **SHA-256 hash**; the full key is shown once, when it is created.
- An environment may have **several active keys at once**, so a key can be rotated without downtime: create the new key, deploy it, then revoke the old one. Each key has an opaque `id` and a visible `prefix` (for example `fp_prod_a1b2`) so it can be identified without revealing it. Keys are managed from the CLI (`flagpost:key:create`, `flagpost:key:list`, `flagpost:key:revoke`), never over HTTP.
- A missing or unknown credential returns `401`. A valid credential of the wrong kind returns `403`.
- Both kinds of key are **server-side secrets**. Never ship them in browser or mobile code.

## 3. SDK endpoint

### `GET /v1/sdk/flags`

Returns every flag of the environment that the key belongs to.

Request headers: `X-Api-Key` (required), `If-None-Match` (optional).

`200 OK`:

```json
{
  "schemaVersion": 1,
  "environment": "prod",
  "flags": [
    { "key": "dark-mode",    "enabled": true,  "percentage": 100, "allowList": [] },
    { "key": "new-checkout", "enabled": true,  "percentage": 20,  "allowList": ["alice"] }
  ]
}
```

- `flags` are sorted by `key` (ordinal) so that the response is byte-stable.
- Response headers: `ETag: "<sha-256 hex of the body>"`, `Cache-Control: private, no-cache` and `Vary: X-Api-Key`.
- `Vary` is mandatory: the same URL returns a different payload for every environment key, and `private` keeps shared caches from storing it.
- `If-None-Match` is compared with weak comparison (RFC 9110, section 13.1.2). A comma-separated list, `W/` prefixes and `*` are accepted. On a match the API returns `304 Not Modified` with no body and repeats the `ETag`, `Cache-Control` and `Vary` headers.
- Clients ignore unknown fields. They must reject a `schemaVersion` they do not understand and keep using their last snapshot.
- A payload that does not deserialize, or whose fields have the wrong type (`percentage` as a string, `allowList` as an object), is rejected as a whole: the client logs it without the key and keeps the previous snapshot. A partially accepted snapshot is never applied.

## 4. Management endpoints (admin key)

| Method and path | Purpose | Success |
|---|---|---|
| `POST /v1/flags` | Create a flag | `201` + `Location` |
| `GET /v1/flags` | List flags | `200` |
| `GET /v1/flags/{key}` | Get one flag | `200` |
| `PATCH /v1/flags/{key}` | Update the description | `200` |
| `DELETE /v1/flags/{key}` | Delete a flag | `204` |
| `PUT /v1/flags/{key}/environments/{env}` | Replace the configuration in one environment | `200` |
| `POST /v1/flags/{key}/environments/{env}/explain` | Evaluate for a user and explain the result | `200` |
| `GET /up` | Health check (no authentication) | `200` |

Every success response that represents a flag uses this **flag object** (the `explain` response is the exception):

```json
{
  "key": "new-checkout",
  "description": "Redesigned checkout flow",
  "createdAt": "2026-11-19T15:04:05Z",
  "updatedAt": "2026-11-19T16:30:00Z",
  "environments": {
    "dev":     { "enabled": false, "percentage": 100, "allowList": [] },
    "staging": { "enabled": false, "percentage": 100, "allowList": [] },
    "prod":    { "enabled": false, "percentage": 100, "allowList": [] }
  }
}
```

`GET /v1/flags` returns `{ "flags": [ ... ] }`, sorted by `key` (ordinal). Version 1 has no pagination because an installation is capped at 500 flags. `allowList` order is preserved and duplicates are already removed. `updatedAt` changes on every write to the flag or to any of its environments.

### Create a flag

`POST /v1/flags`

```json
{ "key": "new-checkout", "description": "Redesigned checkout flow" }
```

`201 Created`, with `Location: /v1/flags/new-checkout`. The flag starts in every environment as `enabled: false`, `percentage: 100`, `allowList: []`, so turning it on is a single switch. The body is the flag object shown above (`createdAt` and `updatedAt` are equal at creation).

`description` is optional, may be `null`, and is limited to 500 characters.

### Read flags

- `GET /v1/flags` → `200` with `{ "flags": [ ... ] }`.
- `GET /v1/flags/{key}` → `200` with one flag object, or `404` if it does not exist.

### Update the description

`PATCH /v1/flags/new-checkout`

```json
{ "description": "New checkout, gradual rollout" }
```

`200` with the updated flag object. Only `description` may be present; any other field returns `422`. An empty string or `null` clears it.

### Replace an environment configuration

`PUT /v1/flags/new-checkout/environments/prod`

```json
{ "enabled": true, "percentage": 20, "allowList": ["alice"] }
```

All three fields are required (full replace, not a patch). Validation rules are in the evaluation spec, section 5. A configuration that would push the environment over its allow-list budget returns `422` (section 7). The response is `200` with the full flag object.

### Explain an evaluation

`POST /v1/flags/new-checkout/environments/prod/explain`

```json
{ "userId": "Alice", "defaultValue": false }
```

`userId` is optional and `defaultValue` defaults to `false`.

```json
{ "value": true, "reason": "PERCENTAGE_IN", "bucket": 17 }
```

`bucket` is an integer when the algorithm reached step 7 (its value is in 0–99) and `null` otherwise. This endpoint is the PHP implementation of the shared algorithm and exists to answer "why did this user get this result?".

The example above uses `Alice` with a capital A, whose bucket is 17 (vector B03). The lowercase `alice` used earlier in this document is a **different id**: it is in the example allow list, so `explain` returns `ALLOW_LIST` with `bucket: null`.

## 5. Errors

```json
{
  "type": "https://flagpost.dev/problems/validation-error",
  "title": "Validation failed",
  "status": 422,
  "detail": "One or more fields are invalid.",
  "errors": { "percentage": ["Must be an integer between 0 and 100."] }
}
```

| Status | When |
|---|---|
| `400` | Malformed JSON |
| `401` | Missing or unknown credential |
| `403` | Valid credential of the wrong kind |
| `404` | Unknown flag or environment |
| `409` | A flag with that key already exists |
| `405` | Method not allowed on that path |
| `413` | Request body larger than the limit (1 MiB) |
| `415` | A request with a body did not send `Content-Type: application/json` |
| `422` | Validation error (key grammar, percentage, allow list size or budget, flag limit of 500) |
| `429` | Rate limit exceeded; includes `Retry-After` |
| `500` | Unexpected error; the body never contains a stack trace |

Problem `type` values are identifiers; they do not need to resolve to a live page. Error bodies never echo credentials or the contents of a request.

## 6. Rate limits

| Scope | Limit |
|---|---|
| `GET /v1/sdk/flags`, per environment key | 120 requests per minute |
| Management endpoints, per IP | 60 requests per minute |

The SDK polls every 30 seconds by default, which uses 2 requests per minute.

Every application instance shares its environment key, so the limit is per environment, not per process: 60 instances polling every 30 s already reach 120 requests per minute. The SDK therefore adds jitter of ±20 % to the interval, honors `Retry-After` on `429`, and backs off exponentially on `401`, `403` and `429` while keeping its last good snapshot.

Behind a reverse proxy, the API must trust the proxy headers (Laravel's `TrustProxies`). Otherwise every management request appears to come from the proxy's IP and the limit applies to the proxy.

## 7. Limits

| Item | Limit |
|---|---|
| Flags per installation | 500 |
| Entries per allow list | 1000 |
| User id length in an allow list | 256 bytes (UTF-8) |
| Total allow-list data per environment (sum of entry bytes + 1 per entry) | 1 MiB |
| Flag key | 1–64 characters, `^[a-z0-9][a-z0-9._-]{0,63}$` |
| Description | 500 characters |
| Request body | 1 MiB |

The 1 MiB per-environment budget bounds the SDK payload: allow-list data dominates it, and with flag metadata the response stays around 2 MiB in the worst case after JSON escaping. A write that would exceed the budget returns `422`.

The 1 MiB body limit is deliberately larger than the worst-case single write: 1000 entries of 256 bytes, fully JSON-escaped, is about 503 KiB. Without that margin, a configuration that validation accepts could be impossible to submit.

---

## Resumen en español

Contrato HTTP v1 entre la API en PHP y el SDK en C#:

- **Dos tipos de llave:** la de administración (`Authorization: Bearer`) y una por entorno (`X-Api-Key`), esta última solo para leer las reglas de su entorno. De las llaves de entorno se guarda solo el hash SHA-256. Puede haber varias llaves activas por entorno para rotarlas sin corte; se identifican por un `id` y un prefijo visible y se gestionan por CLI, nunca por HTTP.
- **Endpoint para SDKs:** `GET /v1/sdk/flags` devuelve todas las reglas ordenadas, con `ETag`, `Cache-Control: private, no-cache`, `Vary: X-Api-Key` y respuesta `304` si nada cambió.
- **Administración:** crear, listar, consultar, editar y borrar flags, configurar cada entorno con reemplazo completo, y `explain` para saber por qué un usuario obtuvo cierto resultado. Las respuestas de administración devuelven el objeto flag con `createdAt` y `updatedAt`; `explain` es la excepción.
- **Límites:** 500 flags, 1000 entradas por lista, 1 MiB de datos de listas por entorno y 1 MiB por petición; el presupuesto por entorno mantiene pequeño el payload del SDK.
- **Errores** en formato RFC 9457, sin trazas ni credenciales, y límites de tasa documentados. El SDK añade jitter y respeta `Retry-After`.
