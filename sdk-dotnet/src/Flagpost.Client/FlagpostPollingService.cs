using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.Abstractions;

namespace Flagpost.Client;

internal sealed class FlagpostPollingService : BackgroundService
{
    private readonly FlagClient _client;
    private readonly ILogger _logger;

    public FlagpostPollingService(FlagClient client, ILogger<FlagpostPollingService>? logger = null)
    {
        _client = client;
        _logger = (ILogger?)logger ?? NullLogger.Instance;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        // Fetch immediately at startup so the app can become ready quickly.
        while (!stoppingToken.IsCancellationRequested)
        {
            Sync.FlagFetchResult? result = null;
            try
            {
                result = await _client.RefreshOnceAsync(stoppingToken).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Flagpost polling iteration failed.");
            }

            var delay = _client.GetNextDelay(result);
            try
            {
                await Task.Delay(delay, stoppingToken).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
        }
    }
}
