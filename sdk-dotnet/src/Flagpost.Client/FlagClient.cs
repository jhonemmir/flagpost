using Flagpost.Client.Evaluation;
using Flagpost.Client.Sync;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.Abstractions;

namespace Flagpost.Client;

/// <summary>
/// Thread-safe client that evaluates flags locally against an in-memory snapshot.
/// </summary>
public sealed class FlagClient : IFlagpostClient, IDisposable
{
    private readonly FlagFetcher _fetcher;
    private readonly FlagpostOptions _options;
    private readonly ILogger _logger;
    private readonly Random _random;
    private readonly SemaphoreSlim _refreshLock = new(1, 1);
    private readonly object _randomLock = new();

    private FlagSnapshot _snapshot = FlagSnapshot.Empty;
    private int _failureCount;

    public FlagClient(FlagpostOptions options, HttpClient httpClient, ILogger<FlagClient>? logger = null, Random? random = null)
    {
        ArgumentNullException.ThrowIfNull(options);
        ArgumentNullException.ThrowIfNull(httpClient);
        options.Validate();

        _options = options;
        _logger = (ILogger?)logger ?? NullLogger.Instance;
        _random = random ?? Random.Shared;
        _fetcher = new FlagFetcher(httpClient, options, _logger);
    }

    public bool IsReady => !ReferenceEquals(Volatile.Read(ref _snapshot), FlagSnapshot.Empty);

    public bool IsEnabled(string flagKey, string? userId, bool defaultValue = false)
    {
        return Evaluate(flagKey, userId, defaultValue).Value;
    }

    public EvaluationResult Evaluate(string flagKey, string? userId, bool defaultValue = false)
    {
        var snapshot = Volatile.Read(ref _snapshot);
        if (ReferenceEquals(snapshot, FlagSnapshot.Empty))
        {
            return new EvaluationResult(defaultValue, ReasonCode.NotReady);
        }

        if (!snapshot.Flags.TryGetValue(flagKey, out var flag))
        {
            return new EvaluationResult(defaultValue, ReasonCode.NotFound);
        }

        return EvaluateSnapshot(flagKey, flag, userId, defaultValue);
    }

    public async Task RefreshAsync(CancellationToken cancellationToken = default)
    {
        await _refreshLock.WaitAsync(cancellationToken).ConfigureAwait(false);
        try
        {
            var current = Volatile.Read(ref _snapshot);
            var result = await _fetcher.FetchAsync(
                string.IsNullOrEmpty(current.ETag) ? null : current.ETag,
                cancellationToken).ConfigureAwait(false);

            switch (result.Status)
            {
                case FlagFetchStatus.Success when result.Snapshot is not null:
                    Interlocked.Exchange(ref _failureCount, 0);
                    Volatile.Write(ref _snapshot, result.Snapshot);
                    break;
                case FlagFetchStatus.NotModified:
                    Interlocked.Exchange(ref _failureCount, 0);
                    break;
                case FlagFetchStatus.Failure:
                    Interlocked.Increment(ref _failureCount);
                    break;
            }
        }
        finally
        {
            _refreshLock.Release();
        }
    }

    internal TimeSpan GetNextDelay(FlagFetchResult? lastResult)
    {
        if (lastResult?.Status == FlagFetchStatus.Failure)
        {
            var failures = Math.Max(1, Volatile.Read(ref _failureCount));
            var backoffMs = _options.InitialBackoff.TotalMilliseconds * Math.Pow(2, failures - 1);
            var cappedMs = Math.Min(backoffMs, _options.MaxBackoff.TotalMilliseconds);

            if (lastResult.RetryAfter is { } retryAfter)
            {
                cappedMs = Math.Max(cappedMs, retryAfter.TotalMilliseconds);
            }

            return ApplyJitter(TimeSpan.FromMilliseconds(cappedMs));
        }

        return ApplyJitter(_options.EffectivePollInterval);
    }

    internal async Task<FlagFetchResult> RefreshOnceAsync(CancellationToken cancellationToken = default)
    {
        await _refreshLock.WaitAsync(cancellationToken).ConfigureAwait(false);
        try
        {
            var current = Volatile.Read(ref _snapshot);
            var result = await _fetcher.FetchAsync(
                string.IsNullOrEmpty(current.ETag) ? null : current.ETag,
                cancellationToken).ConfigureAwait(false);

            switch (result.Status)
            {
                case FlagFetchStatus.Success when result.Snapshot is not null:
                    Interlocked.Exchange(ref _failureCount, 0);
                    Volatile.Write(ref _snapshot, result.Snapshot);
                    break;
                case FlagFetchStatus.NotModified:
                    Interlocked.Exchange(ref _failureCount, 0);
                    break;
                case FlagFetchStatus.Failure:
                    Interlocked.Increment(ref _failureCount);
                    break;
            }

            return result;
        }
        finally
        {
            _refreshLock.Release();
        }
    }

    public void Dispose()
    {
        _refreshLock.Dispose();
    }

    private static EvaluationResult EvaluateSnapshot(string flagKey, SnapshotFlag flag, string? userId, bool defaultValue)
    {
        if (!flag.Enabled)
        {
            return new EvaluationResult(false, ReasonCode.Disabled);
        }

        var hasUser = !string.IsNullOrEmpty(userId);

        if (hasUser && flag.ContainsAllowList(userId!))
        {
            return new EvaluationResult(true, ReasonCode.AllowList);
        }

        if (flag.Percentage == 100)
        {
            return new EvaluationResult(true, ReasonCode.FullRollout);
        }

        if (flag.Percentage == 0)
        {
            return new EvaluationResult(false, ReasonCode.NoRollout);
        }

        if (!hasUser)
        {
            return new EvaluationResult(defaultValue, ReasonCode.MissingUserId);
        }

        return Bucketing.Bucket(flagKey, userId!) < flag.Percentage
            ? new EvaluationResult(true, ReasonCode.PercentageIn)
            : new EvaluationResult(false, ReasonCode.PercentageOut);
    }

    private TimeSpan ApplyJitter(TimeSpan delay)
    {
        if (_options.JitterFactor <= 0)
        {
            return delay;
        }

        double factor;
        lock (_randomLock)
        {
            // ±jitter: [1-factor, 1+factor]
            factor = 1 + ((_random.NextDouble() * 2 - 1) * _options.JitterFactor);
        }

        var ms = Math.Max(0, delay.TotalMilliseconds * factor);
        return TimeSpan.FromMilliseconds(ms);
    }
}
