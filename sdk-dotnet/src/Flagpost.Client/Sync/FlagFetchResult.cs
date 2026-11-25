namespace Flagpost.Client.Sync;

internal sealed class FlagFetchResult
{
    private FlagFetchResult(FlagFetchStatus status, FlagSnapshot? snapshot = null, string? etag = null, TimeSpan? retryAfter = null)
    {
        Status = status;
        Snapshot = snapshot;
        ETag = etag;
        RetryAfter = retryAfter;
    }

    public FlagFetchStatus Status { get; }

    public FlagSnapshot? Snapshot { get; }

    public string? ETag { get; }

    public TimeSpan? RetryAfter { get; }

    public static FlagFetchResult NotModified(string? etag) => new(FlagFetchStatus.NotModified, etag: etag);

    public static FlagFetchResult Success(FlagSnapshot snapshot, string? etag) => new(FlagFetchStatus.Success, snapshot, etag);

    public static FlagFetchResult Failure(TimeSpan? retryAfter = null) => new(FlagFetchStatus.Failure, retryAfter: retryAfter);
}

internal enum FlagFetchStatus
{
    Success,
    NotModified,
    Failure,
}
