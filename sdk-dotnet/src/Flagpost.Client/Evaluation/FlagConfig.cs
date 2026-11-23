namespace Flagpost.Client.Evaluation;

/// <summary>Configuration of one flag in one environment.</summary>
public sealed record FlagConfig(
    bool Enabled,
    int Percentage,
    IReadOnlyList<string> AllowList);
