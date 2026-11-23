using Flagpost.Client.Evaluation;
using Flagpost.Client.Tests.Contract;

namespace Flagpost.Client.Tests;

public class EvaluatorContractTests
{
    public static IEnumerable<object[]> CaseIds() =>
        ContractFile.Current.EvaluationCases.Select(item => new object[] { item.Id });

    [Theory]
    [MemberData(nameof(CaseIds))]
    public void Evaluator_matches_every_shared_case(string id)
    {
        var testCase = ContractFile.Current.EvaluationCases.Single(item => item.Id == id);
        var flag = testCase.Flag?.ToConfig();

        var result = Evaluator.Evaluate(
            testCase.FlagKey,
            flag,
            testCase.UserId,
            testCase.DefaultValue);

        Assert.Equal(testCase.Expected.Value, result.Value);
        Assert.Equal(testCase.Expected.Reason, result.Reason.ToWireValue());
    }
}
