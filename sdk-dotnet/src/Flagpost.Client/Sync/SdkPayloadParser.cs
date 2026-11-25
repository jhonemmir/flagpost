using System.Collections.Frozen;
using System.Text.Json;

namespace Flagpost.Client.Sync;

internal static class SdkPayloadParser
{
    private const int SupportedSchemaVersion = 1;

    public static bool TryParse(string json, out FlagSnapshot snapshot)
    {
        snapshot = FlagSnapshot.Empty;

        try
        {
            using var doc = JsonDocument.Parse(json);
            var root = doc.RootElement;
            if (root.ValueKind != JsonValueKind.Object)
            {
                return false;
            }

            if (!TryGetInt32(root, "schemaVersion", out var schemaVersion) || schemaVersion != SupportedSchemaVersion)
            {
                return false;
            }

            if (!root.TryGetProperty("flags", out var flagsElement) || flagsElement.ValueKind != JsonValueKind.Array)
            {
                return false;
            }

            var flags = new Dictionary<string, SnapshotFlag>(StringComparer.Ordinal);
            foreach (var flagElement in flagsElement.EnumerateArray())
            {
                if (flagElement.ValueKind != JsonValueKind.Object)
                {
                    return false;
                }

                if (!TryGetString(flagElement, "key", out var key) || string.IsNullOrEmpty(key))
                {
                    return false;
                }

                if (!TryGetBoolean(flagElement, "enabled", out var enabled))
                {
                    return false;
                }

                if (!TryGetInt32(flagElement, "percentage", out var percentage)
                    || percentage < 0
                    || percentage > 100)
                {
                    return false;
                }

                if (!flagElement.TryGetProperty("allowList", out var allowListElement)
                    || allowListElement.ValueKind != JsonValueKind.Array)
                {
                    return false;
                }

                var allowList = new List<string>();
                var seen = new HashSet<string>(StringComparer.Ordinal);
                var validAllowList = true;
                foreach (var entry in allowListElement.EnumerateArray())
                {
                    if (entry.ValueKind != JsonValueKind.String)
                    {
                        validAllowList = false;
                        break;
                    }

                    var value = entry.GetString();
                    if (string.IsNullOrEmpty(value))
                    {
                        validAllowList = false;
                        break;
                    }

                    if (seen.Add(value))
                    {
                        allowList.Add(value);
                    }
                }

                if (!validAllowList)
                {
                    return false;
                }

                // Duplicate keys are rejected to avoid ambiguous snapshots.
                if (!flags.TryAdd(key, new SnapshotFlag(enabled, percentage, allowList)))
                {
                    return false;
                }
            }

            snapshot = new FlagSnapshot(
                flags.ToFrozenDictionary(StringComparer.Ordinal),
                string.Empty);
            return true;
        }
        catch (JsonException)
        {
            return false;
        }
        catch (InvalidOperationException)
        {
            return false;
        }
    }

    private static bool TryGetBoolean(JsonElement element, string property, out bool value)
    {
        value = default;
        if (!element.TryGetProperty(property, out var prop))
        {
            return false;
        }

        if (prop.ValueKind is not JsonValueKind.True and not JsonValueKind.False)
        {
            return false;
        }

        value = prop.GetBoolean();
        return true;
    }

    private static bool TryGetInt32(JsonElement element, string property, out int value)
    {
        value = default;
        if (!element.TryGetProperty(property, out var prop) || prop.ValueKind != JsonValueKind.Number)
        {
            return false;
        }

        return prop.TryGetInt32(out value);
    }

    private static bool TryGetString(JsonElement element, string property, out string value)
    {
        value = string.Empty;
        if (!element.TryGetProperty(property, out var prop) || prop.ValueKind != JsonValueKind.String)
        {
            return false;
        }

        value = prop.GetString() ?? string.Empty;
        return true;
    }
}
