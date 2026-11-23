using Flagpost.Client.Evaluation;
using Flagpost.Client.Tests.Contract;

namespace Flagpost.Client.Tests;

public class BucketingContractTests
{
    public static IEnumerable<object[]> VectorIds() =>
        ContractFile.Current.BucketVectors.Select(vector => new object[] { vector.Id });

    [Theory]
    [MemberData(nameof(VectorIds))]
    public void Bucket_matches_every_shared_vector(string id)
    {
        var vector = ContractFile.Current.BucketVectors.Single(item => item.Id == id);
        var actual = Bucketing.BucketDetailed(vector.FlagKey, vector.UserId);

        Assert.Equal(vector.First4BytesHex, actual.Hex);
        Assert.Equal(Convert.ToUInt32(vector.First4BytesHex, 16), actual.Value);
        Assert.Equal(vector.Bucket, actual.Bucket);
    }
}
