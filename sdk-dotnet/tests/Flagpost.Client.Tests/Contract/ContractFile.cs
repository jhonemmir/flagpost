using System.Text.Json;
using System.Text.Json.Serialization;
using Flagpost.Client.Evaluation;

namespace Flagpost.Client.Tests.Contract;

internal static class ContractFile
{
    private static readonly Lazy<ContractFileData> Instance = new(Load);

    private static readonly JsonSerializerOptions SerializerOptions = new()
    {
        PropertyNameCaseInsensitive = true,
    };

    public static ContractFileData Current => Instance.Value;

    private static ContractFileData Load()
    {
        var path = Path.Combine(AppContext.BaseDirectory, "vectors", "evaluation.json");
        using var stream = File.OpenRead(path);

        var contract = JsonSerializer.Deserialize<ContractFileData>(stream, SerializerOptions)
            ?? throw new InvalidOperationException($"Could not read the contract file at {path}.");

        if (contract.VectorsVersion != 1)
        {
            throw new InvalidOperationException(
                $"Unsupported vectorsVersion: {contract.VectorsVersion}.");
        }

        return contract;
    }
}

internal sealed record ContractFileData(
    [property: JsonPropertyName("vectorsVersion")] int VectorsVersion,
    [property: JsonPropertyName("bucketVectors")] List<BucketVector> BucketVectors,
    [property: JsonPropertyName("evaluationCases")] List<EvaluationCase> EvaluationCases);

internal sealed record BucketVector(
    [property: JsonPropertyName("id")] string Id,
    [property: JsonPropertyName("flagKey")] string FlagKey,
    [property: JsonPropertyName("userId")] string UserId,
    [property: JsonPropertyName("first4BytesHex")] string First4BytesHex,
    [property: JsonPropertyName("bucket")] int Bucket);

internal sealed record EvaluationCase(
    [property: JsonPropertyName("id")] string Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("flagKey")] string FlagKey,
    [property: JsonPropertyName("flag")] FlagDto? Flag,
    [property: JsonPropertyName("userId")] string? UserId,
    [property: JsonPropertyName("defaultValue")] bool DefaultValue,
    [property: JsonPropertyName("expected")] ExpectedDto Expected);

internal sealed record FlagDto(
    [property: JsonPropertyName("enabled")] bool Enabled,
    [property: JsonPropertyName("percentage")] int Percentage,
    [property: JsonPropertyName("allowList")] List<string> AllowList)
{
    public FlagConfig ToConfig() => new(Enabled, Percentage, AllowList);
}

internal sealed record ExpectedDto(
    [property: JsonPropertyName("value")] bool Value,
    [property: JsonPropertyName("reason")] string Reason);
