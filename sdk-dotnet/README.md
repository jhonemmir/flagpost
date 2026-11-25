# Flagpost.Client

Feature flags with client-side evaluation for .NET 8 and .NET 10. The SDK fetches
a snapshot of the flags for one environment, keeps the last good copy and
evaluates every flag in process. User ids never leave the application.

> **Preview:** `0.1.0-alpha.1`. The package name and API may still change before
> `1.0.0`.

## Install

```bash
dotnet add package Flagpost.Client --prerelease
```

The environment key is a server-side secret. Never use it in browser or mobile
code.

## Register the client

```csharp
using Flagpost.Client;

builder.Services.AddFlagpost(options =>
{
    options.BaseUrl = new Uri("https://flagpost-api.fly.dev");
    options.ApiKey = builder.Configuration["Flagpost:ApiKey"]!;
});
```

`AddFlagpost` registers `IFlagpostClient`, its `HttpClient` and a background
polling service. The first fetch happens immediately when the host starts.
Later fetches use `ETag`/`If-None-Match` and keep the previous snapshot on
failures.

## Evaluate a flag

```csharp
using System.Security.Claims;

app.MapGet("/checkout", (IFlagpostClient flags, HttpContext context) =>
{
    var userId = context.User.FindFirstValue(ClaimTypes.NameIdentifier);
    var newCheckout = flags.IsEnabled("new-checkout", userId, defaultValue: false);

    return newCheckout
        ? Results.Ok("new checkout")
        : Results.Ok("classic checkout");
});
```

Use `Evaluate` when the reason matters:

```csharp
using Flagpost.Client.Evaluation;

var result = flags.Evaluate("new-checkout", userId, defaultValue: false);
// result.Value                  -> true or false
// result.Reason                 -> ReasonCode.AllowList, ReasonCode.PercentageIn, ...
// result.Reason.ToWireValue()   -> "ALLOW_LIST", "PERCENTAGE_IN", ...
```

Before the first successful fetch, the client returns `defaultValue` with
`ReasonCode.NotReady`. A rejected snapshot leaves the previous one active, and
`IsReady` stays true once a good snapshot has been loaded. Evaluation never
throws because of a missing flag or a sync failure.

## Options

| Option | Default | Meaning |
|---|---|---|
| `BaseUrl` | `http://localhost:8000` | Flagpost API base URL |
| `ApiKey` | required | Environment key (`fp_dev_...`) |
| `PollInterval` | 30 s | Refresh interval; values below 5 s become 5 s |
| `HttpTimeout` | 10 s | Timeout for one fetch |
| `InitialBackoff` | 5 s | First delay after `401`, `403`, `429` or a transport error |
| `MaxBackoff` | 2 min | Backoff cap |
| `JitterFactor` | 0.2 | Random jitter around the interval (±20 %) |

The polling service honors `Retry-After` on `429` and backs off exponentially
on `401`, `403`, `429` and transport failures.

## Links

- [Repository](https://github.com/jhonemmir/flagpost)
- [Evaluation spec](https://github.com/jhonemmir/flagpost/blob/main/docs/evaluation-spec.md)
- [API contract](https://github.com/jhonemmir/flagpost/blob/main/docs/api-contract.md)
