namespace Flagpost.Client;

/// <summary>
/// Options for connecting to a Flagpost API environment.
/// </summary>
public sealed class FlagpostOptions
{
    /// <summary>Base URL of the Flagpost API, for example https://flags.example.com.</summary>
    public Uri BaseUrl { get; set; } = new("http://localhost:8000");

    /// <summary>Environment key sent as <c>X-Api-Key</c>. Server-side secret only.</summary>
    public string ApiKey { get; set; } = string.Empty;

    /// <summary>Polling interval. Minimum effective value is 5 seconds.</summary>
    public TimeSpan PollInterval { get; set; } = TimeSpan.FromSeconds(30);

    /// <summary>HTTP timeout for each fetch.</summary>
    public TimeSpan HttpTimeout { get; set; } = TimeSpan.FromSeconds(10);

    /// <summary>Initial backoff after 401/403/429 or transport failures. Capped internally.</summary>
    public TimeSpan InitialBackoff { get; set; } = TimeSpan.FromSeconds(5);

    /// <summary>Maximum backoff delay.</summary>
    public TimeSpan MaxBackoff { get; set; } = TimeSpan.FromMinutes(2);

    /// <summary>Jitter factor applied to poll interval and backoff. 0.2 means ±20%.</summary>
    public double JitterFactor { get; set; } = 0.2;

    internal TimeSpan EffectivePollInterval => PollInterval < TimeSpan.FromSeconds(5)
        ? TimeSpan.FromSeconds(5)
        : PollInterval;

    internal void Validate()
    {
        if (BaseUrl is null)
        {
            throw new ArgumentException("BaseUrl is required.", nameof(BaseUrl));
        }

        if (!BaseUrl.IsAbsoluteUri)
        {
            throw new ArgumentException("BaseUrl must be an absolute URI.", nameof(BaseUrl));
        }

        if (string.IsNullOrWhiteSpace(ApiKey))
        {
            throw new ArgumentException("ApiKey is required.", nameof(ApiKey));
        }

        if (HttpTimeout <= TimeSpan.Zero)
        {
            throw new ArgumentOutOfRangeException(nameof(HttpTimeout), "HttpTimeout must be positive.");
        }

        if (InitialBackoff <= TimeSpan.Zero)
        {
            throw new ArgumentOutOfRangeException(nameof(InitialBackoff), "InitialBackoff must be positive.");
        }

        if (MaxBackoff < InitialBackoff)
        {
            throw new ArgumentOutOfRangeException(nameof(MaxBackoff), "MaxBackoff must be >= InitialBackoff.");
        }

        if (JitterFactor < 0 || JitterFactor > 1)
        {
            throw new ArgumentOutOfRangeException(nameof(JitterFactor), "JitterFactor must be between 0 and 1.");
        }
    }
}
