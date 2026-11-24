<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ProblemDetails;
use App\Models\ApiKey;
use App\Support\FlagpostConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateAdminKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = FlagpostConfig::adminKey();
        $provided = $request->bearerToken();

        if ($configured === '') {
            return ProblemDetails::response(500, 'The server is missing its admin key.');
        }

        if ($provided !== null && hash_equals($configured, $provided)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        if ($provided === null) {
            $environmentKey = (string) $request->header('X-Api-Key', '');

            if ($environmentKey !== '' && ApiKey::findActive($environmentKey) !== null) {
                return ProblemDetails::response(
                    403,
                    'The credential is valid but not allowed for this endpoint.',
                );
            }
        }

        return ProblemDetails::response(401, 'A valid admin credential is required.');
    }
}
