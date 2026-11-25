using System.Net;
using System.Text;
using Flagpost.Client.Evaluation;
using Flagpost.Client.Sync;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.Abstractions;

namespace Flagpost.Client.Tests;

public class FlagClientTests
{
    [Fact]
    public void Returns_not_ready_before_first_successful_fetch()
    {
        var client = CreateClient(new SequenceHandler(_ => JsonResponse(SnapshotJson(enabled: true, percentage: 100))));

        var result = client.Evaluate("new-checkout", "alice", defaultValue: true);

        Assert.True(result.Value);
        Assert.Equal(ReasonCode.NotReady, result.Reason);
        Assert.False(client.IsReady);
    }

    [Fact]
    public async Task Loads_snapshot_and_evaluates_locally()
    {
        var handler = new SequenceHandler(_ => JsonResponse(SnapshotJson(enabled: true, percentage: 50, allowList: ["vip"])));
        var client = CreateClient(handler);

        await client.RefreshAsync();

        Assert.True(client.IsReady);
        Assert.True(client.IsEnabled("new-checkout", "vip"));
        Assert.True(client.IsEnabled("new-checkout", "user-466")); // bucket 49, inside 50%
        Assert.False(client.IsEnabled("new-checkout", "user-116")); // bucket 50, outside 50%
        Assert.Single(handler.Requests);
        Assert.Equal("fp_test_key", handler.Requests[0].Headers.GetValues("X-Api-Key").Single());
    }

    [Fact]
    public async Task Sends_if_none_match_and_keeps_snapshot_on_304()
    {
        var handler = new SequenceHandler(
            _ => JsonResponse(SnapshotJson(enabled: true, percentage: 100), etag: "\"etag-1\""),
            req =>
            {
                Assert.Equal("\"etag-1\"", req.Headers.IfNoneMatch.Single().Tag);
                return new HttpResponseMessage(HttpStatusCode.NotModified)
                {
                    Headers = { ETag = new System.Net.Http.Headers.EntityTagHeaderValue("\"etag-1\"") },
                };
            });
        var client = CreateClient(handler);

        await client.RefreshAsync();
        await client.RefreshAsync();

        Assert.True(client.IsEnabled("new-checkout", "alice"));
        Assert.Equal(2, handler.Requests.Count);
    }

    [Fact]
    public async Task Keeps_last_good_snapshot_on_http_failure()
    {
        var handler = new SequenceHandler(
            _ => JsonResponse(SnapshotJson(enabled: true, percentage: 100)),
            _ => new HttpResponseMessage(HttpStatusCode.InternalServerError));
        var client = CreateClient(handler);

        await client.RefreshAsync();
        await client.RefreshAsync();

        Assert.True(client.IsEnabled("new-checkout", "alice"));
    }

    [Fact]
    public async Task Keeps_last_good_snapshot_on_invalid_json()
    {
        var handler = new SequenceHandler(
            _ => JsonResponse(SnapshotJson(enabled: true, percentage: 100)),
            _ => JsonResponse("{not-json"));
        var client = CreateClient(handler);

        await client.RefreshAsync();
        await client.RefreshAsync();

        Assert.True(client.IsEnabled("new-checkout", "alice"));
    }

    [Fact]
    public async Task Keeps_last_good_snapshot_on_wrong_typed_field()
    {
        var badPayload = """
            {
              "schemaVersion": 1,
              "revision": 2,
              "generatedAt": "2026-01-01T00:00:00Z",
              "flags": [
                { "key": "new-checkout", "enabled": true, "percentage": "50", "allowList": [] }
              ]
            }
            """;
        var handler = new SequenceHandler(
            _ => JsonResponse(SnapshotJson(enabled: true, percentage: 100)),
            _ => JsonResponse(badPayload));
        var client = CreateClient(handler);

        await client.RefreshAsync();
        await client.RefreshAsync();

        Assert.True(client.IsEnabled("new-checkout", "alice"));
        Assert.Equal(ReasonCode.FullRollout, client.Evaluate("new-checkout", "alice").Reason);
    }

    [Fact]
    public async Task Rejects_unknown_schema_version()
    {
        var badPayload = """
            { "schemaVersion": 2, "revision": 1, "generatedAt": "2026-01-01T00:00:00Z", "flags": [] }
            """;
        var handler = new SequenceHandler(_ => JsonResponse(badPayload));
        var client = CreateClient(handler);

        await client.RefreshAsync();

        Assert.False(client.IsReady);
        Assert.Equal(ReasonCode.NotReady, client.Evaluate("new-checkout", "alice").Reason);
    }

    [Fact]
    public async Task Keeps_snapshot_on_timeout_exception()
    {
        var handler = new SequenceHandler(
            _ => JsonResponse(SnapshotJson(enabled: true, percentage: 100)),
            _ => throw new TaskCanceledException("timeout"));
        var client = CreateClient(handler);

        await client.RefreshAsync();
        await client.RefreshAsync();

        Assert.True(client.IsEnabled("new-checkout", "alice"));
    }

    [Fact]
    public async Task Uses_retry_after_on_429_and_backs_off()
    {
        var handler = new SequenceHandler(
            _ => JsonResponse(SnapshotJson(enabled: true, percentage: 100)),
            _ =>
            {
                var response = new HttpResponseMessage(HttpStatusCode.TooManyRequests);
                response.Headers.RetryAfter = new System.Net.Http.Headers.RetryConditionHeaderValue(TimeSpan.FromSeconds(30));
                return response;
            });
        var client = CreateClient(handler, new FlagpostOptions
        {
            BaseUrl = new Uri("https://flags.example.test"),
            ApiKey = "fp_test_key",
            PollInterval = TimeSpan.FromSeconds(30),
            InitialBackoff = TimeSpan.FromSeconds(5),
            MaxBackoff = TimeSpan.FromMinutes(2),
            JitterFactor = 0,
        });

        await client.RefreshAsync();
        var failureResult = await client.RefreshOnceAsync();

        var delay = client.GetNextDelay(failureResult);
        Assert.True(delay >= TimeSpan.FromSeconds(30));
    }

    [Fact]
    public async Task Exponential_backoff_on_401()
    {
        var handler = new SequenceHandler(
            _ => new HttpResponseMessage(HttpStatusCode.Unauthorized),
            _ => new HttpResponseMessage(HttpStatusCode.Unauthorized),
            _ => new HttpResponseMessage(HttpStatusCode.Unauthorized));
        var client = CreateClient(handler, new FlagpostOptions
        {
            BaseUrl = new Uri("https://flags.example.test"),
            ApiKey = "fp_test_key",
            PollInterval = TimeSpan.FromSeconds(30),
            InitialBackoff = TimeSpan.FromSeconds(5),
            MaxBackoff = TimeSpan.FromMinutes(2),
            JitterFactor = 0,
        });

        var r1 = await client.RefreshOnceAsync();
        var d1 = client.GetNextDelay(r1);
        var r2 = await client.RefreshOnceAsync();
        var d2 = client.GetNextDelay(r2);
        var r3 = await client.RefreshOnceAsync();
        var d3 = client.GetNextDelay(r3);

        Assert.Equal(TimeSpan.FromSeconds(5), d1);
        Assert.Equal(TimeSpan.FromSeconds(10), d2);
        Assert.Equal(TimeSpan.FromSeconds(20), d3);
    }

    [Fact]
    public async Task Does_not_log_api_key()
    {
        var logger = new CapturingLogger<FlagClient>();
        var handler = new SequenceHandler(_ => new HttpResponseMessage(HttpStatusCode.Unauthorized));
        var client = CreateClient(handler, logger: logger, apiKey: "fp_super_secret_key");

        await client.RefreshAsync();

        Assert.DoesNotContain("fp_super_secret_key", logger.AllMessages);
    }

    private static FlagClient CreateClient(
        HttpMessageHandler handler,
        FlagpostOptions? options = null,
        ILogger<FlagClient>? logger = null,
        string apiKey = "fp_test_key")
    {
        options ??= new FlagpostOptions
        {
            BaseUrl = new Uri("https://flags.example.test"),
            ApiKey = apiKey,
            JitterFactor = 0,
        };
        options.ApiKey = apiKey;
        var httpClient = new HttpClient(handler);
        return new FlagClient(options, httpClient, logger ?? NullLogger<FlagClient>.Instance, new Random(7));
    }

    private static HttpResponseMessage JsonResponse(string body, string? etag = null)
    {
        var response = new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new StringContent(body, Encoding.UTF8, "application/json"),
        };
        if (etag is not null)
        {
            response.Headers.ETag = new System.Net.Http.Headers.EntityTagHeaderValue(etag);
        }

        return response;
    }

    private static string SnapshotJson(bool enabled, int percentage, string[]? allowList = null)
    {
        var allow = allowList is null
            ? "[]"
            : "[" + string.Join(",", allowList.Select(x => $"\"{x}\"")) + "]";
        return $$"""
            {
              "schemaVersion": 1,
              "revision": 1,
              "generatedAt": "2026-01-01T00:00:00Z",
              "flags": [
                { "key": "new-checkout", "enabled": {{(enabled ? "true" : "false")}}, "percentage": {{percentage}}, "allowList": {{allow}} }
              ]
            }
            """;
    }

    private sealed class SequenceHandler(params Func<HttpRequestMessage, HttpResponseMessage>[] responses) : HttpMessageHandler
    {
        private readonly Queue<Func<HttpRequestMessage, HttpResponseMessage>> _responses = new(responses);
        public List<HttpRequestMessage> Requests { get; } = [];

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Requests.Add(request);
            if (_responses.Count == 0)
            {
                throw new InvalidOperationException("No more responses configured.");
            }

            return Task.FromResult(_responses.Dequeue()(request));
        }
    }

    private sealed class CapturingLogger<T> : ILogger<T>
    {
        public List<string> Messages { get; } = [];

        public string AllMessages => string.Join(Environment.NewLine, Messages);

        public IDisposable? BeginScope<TState>(TState state) where TState : notnull => null;

        public bool IsEnabled(LogLevel logLevel) => true;

        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter)
        {
            Messages.Add(formatter(state, exception));
            if (exception is not null)
            {
                Messages.Add(exception.ToString());
            }
        }
    }
}
