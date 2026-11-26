# Security policy

## Supported versions

`Flagpost.Client` and the v1 API are supported while the current release line is
active. The latest published version is on
[NuGet](https://www.nuget.org/packages/Flagpost.Client).

| Version | Supported |
|---|---|
| `1.x` | Yes |
| `0.x` preview | No; upgrade to `1.x` |

## Reporting a vulnerability

Please do **not** open a public issue for a security problem. Use GitHub's
private vulnerability reporting:

https://github.com/jhonemmir/flagpost/security/advisories/new

Include the affected version, a minimal reproduction and the impact. I will
acknowledge the report and coordinate a fix and disclosure.

## Secrets

- The admin key and the environment keys are server-side secrets.
- Environment keys are stored only as a SHA-256 hash and are printed once, when
  created. The API never returns them again and never writes them to logs.
- The SDK sends the environment key only in the `X-Api-Key` request header.
- Never put an environment key in browser or mobile code, or in a public
  repository.
- Rotate a leaked key with `php artisan flagpost:key:revoke <id>` and create a
  replacement with `php artisan flagpost:key:create <environment>`.
- Allow lists are visible to anyone holding an environment key. Store opaque
  user identifiers, not email addresses or other personal data.

The API deployment keeps `APP_KEY`, `FLAGPOST_ADMIN_KEY` and `DB_URL` in Fly.io
secrets. They are not in the repository.

## Scope

The project is a small feature-flag service and SDK. The main security
properties are:

- deterministic, local evaluation without sending user ids to the API;
- bearer authentication with constant-time comparison for the admin key;
- hashed environment keys, multiple active keys for rotation, and revocation;
- RFC 9457 problem details without stack traces or credentials;
- input limits that bound request and snapshot sizes;
- no credentials in responses, errors or logs.

The demo API is a portfolio deployment, not a multi-tenant production service.
See the [API contract](docs/api-contract.md) for the full v1 behavior.
