namespace Flagpost.Client.Evaluation;

/// <summary>
/// Pure evaluation of one flag for one user. Steps are evaluated in order and
/// the first one that applies returns.
/// </summary>
public static class Evaluator
{
    public static EvaluationResult Evaluate(
        string flagKey,
        FlagConfig? flag,
        string? userId,
        bool defaultValue)
    {
        if (flag is null)
        {
            return new EvaluationResult(defaultValue, ReasonCode.NotFound);
        }

        if (!flag.Enabled)
        {
            return new EvaluationResult(false, ReasonCode.Disabled);
        }

        var hasUser = !string.IsNullOrEmpty(userId);

        if (hasUser && flag.AllowList.Contains(userId!, StringComparer.Ordinal))
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
}
