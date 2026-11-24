<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\ProblemDetails;
use App\Models\Flag;
use App\Models\FlagEnvironmentConfig;
use App\Support\FlagpostConfig;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SdkFlagsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $environment = $request->attributes->get('environment');

        if (! is_string($environment) || $environment === '') {
            return ProblemDetails::response(401, 'A valid environment key is required.');
        }

        $flags = Flag::query()
            ->with('configs')
            ->get()
            ->sortBy(static fn (Flag $flag): string => $flag->key, SORT_STRING)
            ->values();

        $payloadFlags = [];

        foreach ($flags as $flag) {
            $config = $flag->configs->first(
                static fn (FlagEnvironmentConfig $candidate): bool => $candidate->environment === $environment,
            );

            if ($config === null) {
                $payloadFlags[] = [
                    'key' => $flag->key,
                    'enabled' => false,
                    'percentage' => 100,
                    'allowList' => [],
                ];

                continue;
            }

            $payloadFlags[] = [
                'key' => $flag->key,
                'enabled' => $config->enabled,
                'percentage' => $config->percentage,
                'allowList' => $config->allow_list,
            ];
        }

        $payload = [
            'schemaVersion' => FlagpostConfig::schemaVersion(),
            'environment' => $environment,
            'flags' => $payloadFlags,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $etag = '"'.hash('sha256', $body).'"';

        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
            'Vary' => 'X-Api-Key',
        ];

        $ifNoneMatch = $request->header('If-None-Match');

        if (is_string($ifNoneMatch) && $ifNoneMatch !== '' && $this->matches($ifNoneMatch, $etag)) {
            return response()->noContent(304, $headers);
        }

        return response($body, 200, $headers + ['Content-Type' => 'application/json']);
    }

    private function matches(string $header, string $etag): bool
    {
        $current = trim($etag, '"');

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if (trim($candidate, '"') === $current) {
                return true;
            }
        }

        return false;
    }
}
