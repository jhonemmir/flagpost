using System.Net;
using System.Text;
using Flagpost.Client;
using Flagpost.Client.Evaluation;

var builder = WebApplication.CreateBuilder(args);

var baseUrl = builder.Configuration["Flagpost:BaseUrl"] ?? "https://flagpost-api.fly.dev";
var apiKey = builder.Configuration["Flagpost:ApiKey"];
var pollSeconds = builder.Configuration.GetValue("Flagpost:PollIntervalSeconds", 5);

if (string.IsNullOrWhiteSpace(apiKey))
{
    throw new InvalidOperationException(
        "Set Flagpost__ApiKey to an environment key (fp_prod_...). " +
        "Never put the key in appsettings.json or commit it.");
}

builder.Services.AddFlagpost(options =>
{
    options.BaseUrl = new Uri(baseUrl, UriKind.Absolute);
    options.ApiKey = apiKey;
    options.PollInterval = TimeSpan.FromSeconds(Math.Max(5, pollSeconds));
});

var app = builder.Build();

app.MapGet("/", (IFlagpostClient flags, HttpContext context) =>
{
    const string flagKey = "new-checkout";
    var user = context.Request.Query["user"].FirstOrDefault();

    if (string.IsNullOrWhiteSpace(user))
    {
        user = "alice";
    }

    var result = flags.Evaluate(flagKey, user);
    var bucket = Bucketing.Bucket(flagKey, user);
    var ready = flags.IsReady;
    var value = result.Value;
    var reason = result.Reason.ToWireValue();

    var stateClass = !ready ? "waiting" : value ? "new" : "classic";
    var stateTitle = !ready ? "Waiting for the first snapshot" : value ? "New checkout" : "Classic checkout";
    var stateDescription = !ready
        ? "The SDK has not loaded a snapshot yet. The page will refresh."
        : value
            ? "The new checkout flow is enabled for this user."
            : "The classic checkout flow is active for this user.";

    var html = $$"""
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta http-equiv="refresh" content="2">
            <title>flagpost demo</title>
            <style>
                :root {
                    color-scheme: dark;
                    --bg: #0b1020;
                    --panel: #151b2f;
                    --panel-2: #1d2540;
                    --text: #eef2ff;
                    --muted: #9aa7c7;
                    --line: #2b3658;
                    --green: #4ade80;
                    --amber: #fbbf24;
                    --blue: #60a5fa;
                }
                * { box-sizing: border-box; }
                body {
                    margin: 0;
                    min-height: 100vh;
                    display: grid;
                    place-items: center;
                    padding: 32px;
                    background:
                        radial-gradient(circle at 20% 10%, #1e3a8a55, transparent 40%),
                        radial-gradient(circle at 90% 90%, #0f766e55, transparent 45%),
                        var(--bg);
                    color: var(--text);
                    font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
                }
                main { width: min(980px, 100%); }
                .eyebrow {
                    color: var(--muted);
                    font-size: 13px;
                    letter-spacing: .16em;
                    text-transform: uppercase;
                    margin-bottom: 10px;
                }
                h1 { margin: 0 0 8px; font-size: clamp(32px, 5vw, 52px); letter-spacing: -.03em; }
                .subtitle { margin: 0 0 28px; color: var(--muted); font-size: 17px; }
                .grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 18px; }
                .panel {
                    border: 1px solid var(--line);
                    background: linear-gradient(180deg, #ffffff0a, #ffffff03);
                    border-radius: 22px;
                    padding: 24px;
                    box-shadow: 0 24px 80px #0006;
                }
                .state {
                    min-height: 260px;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    gap: 24px;
                }
                .dot {
                    width: 12px; height: 12px; border-radius: 999px;
                    display: inline-block; margin-right: 8px;
                    background: var(--amber);
                    box-shadow: 0 0 0 6px #fbbf2422;
                }
                .state.new .dot { background: var(--green); box-shadow: 0 0 0 6px #4ade8022; }
                .state.classic .dot { background: var(--blue); box-shadow: 0 0 0 6px #60a5fa22; }
                .state h2 { margin: 0; font-size: 34px; letter-spacing: -.02em; }
                .state p { margin: 8px 0 0; color: var(--muted); line-height: 1.55; }
                .flag-label {
                    display: inline-flex; align-items: center;
                    border: 1px solid var(--line);
                    background: var(--panel-2);
                    color: var(--muted);
                    border-radius: 999px;
                    padding: 8px 12px;
                    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                    font-size: 13px;
                }
                .stats { display: grid; gap: 12px; }
                .row {
                    display: flex; justify-content: space-between; gap: 16px;
                    padding-bottom: 12px; border-bottom: 1px solid var(--line);
                }
                .row:last-child { border-bottom: 0; padding-bottom: 0; }
                .row span { color: var(--muted); }
                .row strong { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 600; }
                .tag {
                    display: inline-block;
                    border-radius: 999px;
                    padding: 4px 10px;
                    font-size: 12px;
                    background: #60a5fa22;
                    color: #bfdbfe;
                }
                .tag.ready { background: #4ade8022; color: #bbf7d0; }
                .tag.waiting { background: #fbbf2422; color: #fde68a; }
                footer {
                    margin-top: 18px;
                    color: var(--muted);
                    font-size: 13px;
                    line-height: 1.6;
                }
                code { color: #c7d2fe; }
                @media (max-width: 760px) {
                    .grid { grid-template-columns: 1fr; }
                    body { padding: 18px; }
                }
            </style>
        </head>
        <body>
            <main>
                <div class="eyebrow">flagpost · client-side evaluation</div>
                <h1>Checkout demo</h1>
                <p class="subtitle">
                    This page evaluates <code>new-checkout</code> locally with
                    <code>Flagpost.Client</code>. Reloads every 2 seconds; the app is never restarted.
                </p>
                <div class="grid">
                    <section class="panel state {{stateClass}}">
                        <div>
                            <span class="dot"></span>
                            <span class="flag-label">{{WebUtility.HtmlEncode(flagKey)}} · {{WebUtility.HtmlEncode(user)}}</span>
                        </div>
                        <div>
                            <h2>{{WebUtility.HtmlEncode(stateTitle)}}</h2>
                            <p>{{WebUtility.HtmlEncode(stateDescription)}}</p>
                        </div>
                        <div>
                            <span class="tag {{(ready ? "ready" : "waiting")}}">
                                {{(ready ? "SDK ready" : "Waiting for snapshot")}}
                            </span>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="stats">
                            <div class="row"><span>User</span><strong>{{WebUtility.HtmlEncode(user)}}</strong></div>
                            <div class="row"><span>Bucket</span><strong>{{bucket}}</strong></div>
                            <div class="row"><span>Value</span><strong>{{(value ? "true" : "false")}}</strong></div>
                            <div class="row"><span>Reason</span><strong>{{WebUtility.HtmlEncode(reason)}}</strong></div>
                            <div class="row"><span>Snapshot</span><strong>{{(ready ? "loaded" : "pending")}}</strong></div>
                        </div>
                    </section>
                </div>
                <footer>
                    Try <code>?user=alice</code> or <code>?user=bob</code>.
                    Change the flag with the API and watch this page update after the next poll.
                    The environment key stays on the server.
                </footer>
            </main>
        </body>
        </html>
        """;

    return Results.Content(html, "text/html; charset=utf-8");
});

app.Run();
