# Changelog

All notable changes to this project are documented here.

## [0.1.0-alpha.1] - 2026-11-25

First preview of `Flagpost.Client`.

### Added

- `FlagClient` with an immutable, atomically replaced snapshot and local
  evaluation.
- `IFlagpostClient.Evaluate` returning the value and a stable reason code,
  including `NOT_READY` before the first successful fetch.
- Background polling with `ETag`/`If-None-Match`, configurable interval,
  ±20 % jitter, `Retry-After` support and exponential backoff on `401`, `403`
  and `429`.
- Payload validation: unknown `schemaVersion`, malformed JSON, wrong field
  types and duplicate keys reject the whole snapshot while the last good
  snapshot stays active.
- `AddFlagpost(options => ...)` registration for `Microsoft.Extensions.DependencyInjection`.
- Multi-target package for `net8.0` and `net10.0`.

### Security

- The environment key is only sent as `X-Api-Key`; it is never logged.
- The SDK is intended for server-side use only.

[0.1.0-alpha.1]: https://github.com/jhonemmir/flagpost/releases/tag/sdk-v0.1.0-alpha.1
