<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use Illuminate\Support\Str;

final class ApiKeyService
{
    /**
     * Issue a new environment key. The plaintext value is returned only here.
     *
     * @return array{apiKey: ApiKey, plainKey: string}
     */
    public static function issue(string $environment): array
    {
        $secret = Str::random(32);
        $plainKey = "fp_{$environment}_{$secret}";

        $apiKey = ApiKey::create([
            'id' => (string) Str::ulid(),
            'environment' => $environment,
            'prefix' => "fp_{$environment}_".substr($secret, 0, 4),
            'hash' => hash('sha256', $plainKey),
        ]);

        return ['apiKey' => $apiKey, 'plainKey' => $plainKey];
    }
}
