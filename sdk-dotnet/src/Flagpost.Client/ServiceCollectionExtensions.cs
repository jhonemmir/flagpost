using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Flagpost.Client;

public static class ServiceCollectionExtensions
{
    /// <summary>
    /// Registers Flagpost client, its HTTP client and a background polling service.
    /// </summary>
    public static IServiceCollection AddFlagpost(this IServiceCollection services, Action<FlagpostOptions> configure)
    {
        ArgumentNullException.ThrowIfNull(services);
        ArgumentNullException.ThrowIfNull(configure);

        services.AddOptions<FlagpostOptions>().Configure(configure).PostConfigure(options => options.Validate());

        services.TryAddSingleton(sp =>
        {
            var options = sp.GetRequiredService<IOptions<FlagpostOptions>>().Value;
            var httpClient = sp.GetRequiredService<IHttpClientFactory>().CreateClient(nameof(FlagClient));
            var logger = sp.GetService<ILogger<FlagClient>>();
            return new FlagClient(options, httpClient, logger);
        });

        services.TryAddSingleton<IFlagpostClient>(sp => sp.GetRequiredService<FlagClient>());

        services.AddHttpClient(nameof(FlagClient), (sp, client) =>
        {
            var options = sp.GetRequiredService<IOptions<FlagpostOptions>>().Value;
            client.Timeout = options.HttpTimeout;
        });

        services.AddHostedService<FlagpostPollingService>();

        return services;
    }
}
