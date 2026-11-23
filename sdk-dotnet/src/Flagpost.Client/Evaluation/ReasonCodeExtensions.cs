namespace Flagpost.Client.Evaluation;

public static class ReasonCodeExtensions
{
    /// <summary>The wire value used by the API and the SDK diagnostics.</summary>
    public static string ToWireValue(this ReasonCode reason) => reason switch
    {
        ReasonCode.NotFound => "NOT_FOUND",
        ReasonCode.Disabled => "DISABLED",
        ReasonCode.AllowList => "ALLOW_LIST",
        ReasonCode.FullRollout => "FULL_ROLLOUT",
        ReasonCode.NoRollout => "NO_ROLLOUT",
        ReasonCode.MissingUserId => "MISSING_USER_ID",
        ReasonCode.PercentageIn => "PERCENTAGE_IN",
        ReasonCode.PercentageOut => "PERCENTAGE_OUT",
        _ => throw new ArgumentOutOfRangeException(nameof(reason), reason, null),
    };
}
