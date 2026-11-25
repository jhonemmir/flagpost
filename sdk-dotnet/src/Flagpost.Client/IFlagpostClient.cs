using Flagpost.Client.Evaluation;

namespace Flagpost.Client;

public interface IFlagpostClient
{
    /// <summary>
    /// Evaluates a flag locally against the latest good snapshot.
    /// Never throws because of missing flags or sync failures.
    /// </summary>
    bool IsEnabled(string flagKey, string? userId, bool defaultValue = false);

    /// <summary>Same as <see cref="IsEnabled"/> but includes the reason code.</summary>
    EvaluationResult Evaluate(string flagKey, string? userId, bool defaultValue = false);

    /// <summary>True after the first successful snapshot has been loaded.</summary>
    bool IsReady { get; }

    /// <summary>Forces an immediate refresh attempt. Used by tests and startup.</summary>
    Task RefreshAsync(CancellationToken cancellationToken = default);
}
