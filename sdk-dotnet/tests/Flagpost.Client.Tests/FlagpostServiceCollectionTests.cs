using System.Net;
using System.Text;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;

namespace Flagpost.Client.Tests;

public class FlagpostServiceCollectionTests
{
    [Fact]
    public void AddFlagpost_registers_client_and_hosted_service()
    {
        var services = new ServiceCollection();
        services.AddLogging();
        services.AddFlagpost(options =>
        {
            options.BaseUrl = new Uri("https://flags.example.test");
            options.ApiKey = "fp_test_key";
        });

        services.AddSingleton<IHttpMessageHandlerFactory>(new TestHandlerFactory(new StubHandler()));

        using var provider = services.BuildServiceProvider();

        var client = provider.GetRequiredService<IFlagpostClient>();
        Assert.NotNull(client);

        var hosted = provider.GetServices<IHostedService>();
        Assert.Contains(hosted, s => s.GetType().Name.Contains("FlagpostPollingService", StringComparison.Ordinal));
    }

    private sealed class TestHandlerFactory(HttpMessageHandler handler) : IHttpMessageHandlerFactory
    {
        public HttpMessageHandler CreateHandler(string name) => handler;
    }

    private sealed class StubHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            const string body = """{"schemaVersion":1,"revision":1,"generatedAt":"2026-01-01T00:00:00Z","flags":[]}""";
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(body, Encoding.UTF8, "application/json"),
            });
        }
    }
}
