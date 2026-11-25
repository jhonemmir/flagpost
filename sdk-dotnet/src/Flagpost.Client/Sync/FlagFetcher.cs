using System.Net;
using System.Net.Http.Headers;
using Microsoft.Extensions.Logging;

namespace Flagpost.Client.Sync;

internal sealed class FlagFetcher
{
    private readonly HttpClient _httpClient;
    private readonly FlagpostOptions _options;
    private readonly ILogger _logger;

    public FlagFetcher(HttpClient httpClient, FlagpostOptions options, ILogger logger)
    {
        _httpClient = httpClient;
        _options = options;
        _logger = logger;
    }

    public async Task<FlagFetchResult> FetchAsync(string? etag, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, BuildUri());
        request.Headers.TryAddWithoutValidation("X-Api-Key", _options.ApiKey);
        request.Headers.Accept.ParseAdd("application/json");
        if (!string.IsNullOrEmpty(etag) && EntityTagHeaderValue.TryParse(etag, out var parsedEtag))
        {
            request.Headers.IfNoneMatch.Add(parsedEtag);
        }

        try
        {
            using var response = await _httpClient.SendAsync(request, cancellationToken).ConfigureAwait(false);
            return await HandleResponseAsync(response, cancellationToken).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Flagpost SDK fetch failed; keeping previous snapshot.");
            return FlagFetchResult.Failure();
        }
    }

    private Uri BuildUri()
    {
        var baseUri = _options.BaseUrl.ToString().TrimEnd('/');
        return new Uri(baseUri + "/v1/sdk/flags", UriKind.Absolute);
    }

    private async Task<FlagFetchResult> HandleResponseAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (response.StatusCode == HttpStatusCode.NotModified)
        {
            return FlagFetchResult.NotModified(NormalizeETag(response.Headers.ETag));
        }

        if (response.IsSuccessStatusCode)
        {
            var body = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
            if (!SdkPayloadParser.TryParse(body, out var snapshot))
            {
                _logger.LogWarning("Flagpost SDK received an invalid snapshot payload; keeping previous snapshot.");
                return FlagFetchResult.Failure();
            }

            var newEtag = NormalizeETag(response.Headers.ETag);
            snapshot = new FlagSnapshot(snapshot.Flags, newEtag ?? string.Empty);
            return FlagFetchResult.Success(snapshot, newEtag);
        }

        var retryAfter = ParseRetryAfter(response);
        if (response.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden or HttpStatusCode.TooManyRequests)
        {
            _logger.LogWarning(
                "Flagpost SDK fetch returned {StatusCode}; backing off and keeping previous snapshot.",
                (int)response.StatusCode);
            return FlagFetchResult.Failure(retryAfter);
        }

        _logger.LogWarning(
            "Flagpost SDK fetch returned unexpected status {StatusCode}; keeping previous snapshot.",
            (int)response.StatusCode);
        return FlagFetchResult.Failure(retryAfter);
    }

    private static string? NormalizeETag(EntityTagHeaderValue? etag)
    {
        return etag?.ToString();
    }

    private static TimeSpan? ParseRetryAfter(HttpResponseMessage response)
    {
        var retryAfter = response.Headers.RetryAfter;
        if (retryAfter is null)
        {
            return null;
        }

        if (retryAfter.Delta is { } delta && delta > TimeSpan.Zero)
        {
            return delta;
        }

        if (retryAfter.Date is { } date)
        {
            var deltaFromDate = date - DateTimeOffset.UtcNow;
            if (deltaFromDate > TimeSpan.Zero)
            {
                return deltaFromDate;
            }
        }

        return null;
    }
}
