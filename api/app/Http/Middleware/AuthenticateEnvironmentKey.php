<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ProblemDetails;
use App\Models\ApiKey;
use App\Support\FlagpostConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateEnvironmentKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainKey = (string) $request->header('X-Api-Key', '');

        if ($plainKey !== '') {
            $apiKey = ApiKey::findActive($plainKey);

            if ($apiKey !== null) {
                $request->attributes->set('environment', $apiKey->environment);
                $request->attributes->set('api_key_id', $apiKey->id);

                /** @var Response $response */
                $response = $next($request);

                return $response;
            }

            return ProblemDetails::response(401, 'A valid environment key is required.');
        }

        $configured = FlagpostConfig::adminKey();
        $provided = $request->bearerToken();

        if ($provided !== null && $configured !== '' && hash_equals($configured, $provided)) {
            return ProblemDetails::response(
                403,
                'The credential is valid but not allowed for this endpoint.',
            );
        }

        return ProblemDetails::response(401, 'A valid environment key is required.');
    }
}
