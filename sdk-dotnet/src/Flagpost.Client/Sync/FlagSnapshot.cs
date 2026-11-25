using System.Collections.Frozen;
using Flagpost.Client.Evaluation;

namespace Flagpost.Client.Sync;

/// <summary>
/// Immutable snapshot of all flags for one environment.
/// </summary>
internal sealed class FlagSnapshot
{
    public static FlagSnapshot Empty { get; } = new(
        FrozenDictionary<string, SnapshotFlag>.Empty,
        string.Empty);

    public FlagSnapshot(IReadOnlyDictionary<string, SnapshotFlag> flags, string etag)
    {
        Flags = flags;
        ETag = etag;
    }

    public IReadOnlyDictionary<string, SnapshotFlag> Flags { get; }

    public string ETag { get; }
}

internal sealed class SnapshotFlag
{
    public SnapshotFlag(bool enabled, int percentage, IReadOnlyList<string> allowList)
    {
        Enabled = enabled;
        Percentage = percentage;
        AllowList = allowList;
        AllowListSet = allowList.Count == 0
            ? FrozenSet<string>.Empty
            : allowList.ToFrozenSet(StringComparer.Ordinal);
    }

    public bool Enabled { get; }

    public int Percentage { get; }

    public IReadOnlyList<string> AllowList { get; }

    public FrozenSet<string> AllowListSet { get; }

    public bool ContainsAllowList(string userId) => AllowListSet.Contains(userId);
}
