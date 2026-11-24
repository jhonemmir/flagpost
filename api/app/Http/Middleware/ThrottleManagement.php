<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ProblemDetails;
use App\Support\FlagpostConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleManagement
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'management:'.$request->ip();
        $limit = FlagpostConfig::rateLimit('management_per_minute');

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return ProblemDetails::response(
                429,
                'Rate limit exceeded.',
                null,
                ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))],
            );
        }

        RateLimiter::hit($key, 60);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
