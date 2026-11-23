using System.Security.Cryptography;
using System.Text;
using Flagpost.Client.Evaluation;

namespace Flagpost.Client.Tests;

public class PropertyTests
{
    [Fact]
    public void Agrees_with_an_independent_oracle_for_1000_users()
    {
        for (var i = 0; i < 1000; i++)
        {
            var userId = $"user-{i}";
            var oracle = OracleBucket("new-checkout", userId);

            Assert.Equal(oracle, Bucketing.Bucket("new-checkout", userId));

            for (var percentage = 1; percentage < 100; percentage++)
            {
                var flag = new FlagConfig(true, percentage, Array.Empty<string>());
                var result = Evaluator.Evaluate("new-checkout", flag, userId, false);

                Assert.Equal(oracle < percentage, result.Value);
                Assert.Equal(
                    oracle < percentage ? ReasonCode.PercentageIn : ReasonCode.PercentageOut,
                    result.Reason);
            }
        }
    }

    [Fact]
    public void Never_removes_a_user_when_the_percentage_grows()
    {
        for (var i = 0; i < 1000; i++)
        {
            var userId = $"user-{i}";
            var wasInside = false;

            for (var percentage = 0; percentage <= 100; percentage++)
            {
                var flag = new FlagConfig(true, percentage, Array.Empty<string>());
                var result = Evaluator.Evaluate("new-checkout", flag, userId, false);

                if (wasInside)
                {
                    Assert.True(result.Value, $"user {userId} left at {percentage}%");
                }

                wasInside |= result.Value;
            }
        }
    }

    [Fact]
    public void Applies_the_steps_in_order()
    {
        var disabled = Evaluator.Evaluate("new-checkout", new FlagConfig(false, 100, ["alice"]), "alice", true);
        Assert.False(disabled.Value);
        Assert.Equal(ReasonCode.Disabled, disabled.Reason);

        var allowed = Evaluator.Evaluate("new-checkout", new FlagConfig(true, 100, ["alice"]), "alice", false);
        Assert.True(allowed.Value);
        Assert.Equal(ReasonCode.AllowList, allowed.Reason);

        var full = Evaluator.Evaluate("new-checkout", new FlagConfig(true, 100, Array.Empty<string>()), null, false);
        Assert.True(full.Value);
        Assert.Equal(ReasonCode.FullRollout, full.Reason);

        var none = Evaluator.Evaluate("new-checkout", new FlagConfig(true, 0, Array.Empty<string>()), null, true);
        Assert.False(none.Value);
        Assert.Equal(ReasonCode.NoRollout, none.Reason);

        var missing = Evaluator.Evaluate("new-checkout", new FlagConfig(true, 50, Array.Empty<string>()), null, true);
        Assert.True(missing.Value);
        Assert.Equal(ReasonCode.MissingUserId, missing.Reason);

        var unknown = Evaluator.Evaluate("new-checkout", null, "alice", true);
        Assert.True(unknown.Value);
        Assert.Equal(ReasonCode.NotFound, unknown.Reason);
    }

    [Fact]
    public void Returns_a_result_for_awkward_inputs_without_throwing()
    {
        string?[] userIds = [null, string.Empty, " ", "a:b", "ñandú", "🚀", "日本語", new string('u', 256)];
        var flags = new FlagConfig?[]
        {
            null,
            new FlagConfig(true, 0, Array.Empty<string>()),
            new FlagConfig(true, 1, Array.Empty<string>()),
            new FlagConfig(true, 50, ["ñandú"]),
            new FlagConfig(true, 99, Array.Empty<string>()),
            new FlagConfig(true, 100, Array.Empty<string>()),
        };

        foreach (var flag in flags)
        {
            foreach (var userId in userIds)
            {
                foreach (var defaultValue in new[] { false, true })
                {
                    var result = Evaluator.Evaluate("new-checkout", flag, userId, defaultValue);

                    Assert.True(Enum.IsDefined(result.Reason));
                }
            }
        }
    }

    /// <summary>Decodes the digest byte by byte instead of using BinaryPrimitives.</summary>
    private static int OracleBucket(string flagKey, string userId)
    {
        var digest = SHA256.HashData(Encoding.UTF8.GetBytes(string.Concat(flagKey, ":", userId)));
        var value = ((uint)digest[0] << 24)
            | ((uint)digest[1] << 16)
            | ((uint)digest[2] << 8)
            | digest[3];

        return (int)(value % 100);
    }
}
