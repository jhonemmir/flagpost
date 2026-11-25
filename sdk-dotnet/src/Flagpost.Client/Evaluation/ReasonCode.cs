namespace Flagpost.Client.Evaluation;

/// <summary>Stable, machine-readable reason codes.</summary>
public enum ReasonCode
{
    NotFound,
    Disabled,
    AllowList,
    FullRollout,
    NoRollout,
    MissingUserId,
    PercentageIn,
    PercentageOut,
    NotReady,
}
