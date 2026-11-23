using System.Buffers.Binary;
using System.Security.Cryptography;
using System.Text;

namespace Flagpost.Client.Evaluation;

/// <summary>
/// Deterministic bucketing shared with the PHP API.
/// The digest input is the UTF-8 encoding of "{flagKey}:{userId}".
/// </summary>
public static class Bucketing
{
    public static int Bucket(string flagKey, string userId) => BucketDetailed(flagKey, userId).Bucket;

    public static BucketResult BucketDetailed(string flagKey, string userId)
    {
        var input = Encoding.UTF8.GetBytes(string.Concat(flagKey, ":", userId));
        Span<byte> digest = stackalloc byte[SHA256.HashSizeInBytes];
        SHA256.HashData(input, digest);

        var value = BinaryPrimitives.ReadUInt32BigEndian(digest[..4]);
        var hex = Convert.ToHexString(digest[..4]).ToLowerInvariant();

        return new BucketResult(hex, value, (int)(value % 100));
    }
}
