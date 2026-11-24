<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ApiKey;
use App\Models\Flag;
use App\Services\ApiKeyService;
use App\Support\FlagpostConfig;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;

final class Api
{
    public const ADMIN_KEY = 'test-admin-key-0123456789abcdef';

    /**
     * @return array<string, string>
     */
    public static function adminHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.self::ADMIN_KEY,
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array{apiKey: ApiKey, plainKey: string}
     */
    public static function environmentKey(string $environment = 'prod'): array
    {
        return ApiKeyService::issue($environment);
    }

    /**
     * @return array<string, string>
     */
    public static function sdkHeaders(string $plainKey): array
    {
        return [
            'X-Api-Key' => $plainKey,
            'Accept' => 'application/json',
        ];
    }

    public static function createFlag(string $key = 'new-checkout', ?string $description = null): Flag
    {
        $flag = Flag::create([
            'key' => $key,
            'description' => $description,
        ]);

        foreach (FlagpostConfig::environments() as $environment) {
            $flag->configs()->create([
                'environment' => $environment,
                'enabled' => false,
                'percentage' => 100,
                'allow_list' => [],
            ]);
        }

        return $flag->load('configs');
    }

    /**
     * @param  list<string>  $allowList
     */
    public static function configure(
        Flag $flag,
        string $environment,
        bool $enabled,
        int $percentage,
        array $allowList = [],
    ): void {
        $flag->configs()->updateOrCreate(
            ['environment' => $environment],
            [
                'enabled' => $enabled,
                'percentage' => $percentage,
                'allow_list' => array_values($allowList),
            ],
        );

        $flag->touch();
        $flag->load('configs');
    }

    /**
     * Send a raw body so tests can exercise JSON tokens that PHP would
     * normalize (50.0, 1e2) and malformed documents.
     *
     * @param  array<string, string>  $headers
     */
    public static function callRaw(
        TestCase $test,
        string $method,
        string $uri,
        string $body,
        array $headers = [],
        string $contentType = 'application/json',
    ): TestResponse {
        $server = [
            'CONTENT_TYPE' => $contentType,
            'HTTP_ACCEPT' => 'application/json',
        ];

        foreach ($headers as $name => $value) {
            $normalized = strtoupper(str_replace('-', '_', $name));

            if ($normalized === 'AUTHORIZATION') {
                $server['HTTP_AUTHORIZATION'] = $value;
            } elseif ($normalized === 'ACCEPT') {
                $server['HTTP_ACCEPT'] = $value;
            } elseif ($normalized === 'CONTENT_TYPE') {
                $server['CONTENT_TYPE'] = $value;
            } else {
                $server['HTTP_'.$normalized] = $value;
            }
        }

        return $test->call($method, $uri, [], [], [], $server, $body);
    }
}
