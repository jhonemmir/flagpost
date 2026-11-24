<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Removes credentials from the request once they have been checked.
 *
 * Downstream code, exception reporting and access logging then see a redacted
 * request instead of the raw keys.
 */
final class ScrubCredentials
{
    private const REDACTED = '[REDACTED]';

    /**
     * @var list<string>
     */
    private const SERVER_KEYS = [
        'HTTP_AUTHORIZATION',
        'REDIRECT_HTTP_AUTHORIZATION',
        'HTTP_PROXY_AUTHORIZATION',
        'REDIRECT_HTTP_PROXY_AUTHORIZATION',
        'HTTP_X_API_KEY',
        'REDIRECT_HTTP_X_API_KEY',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $this->scrub($request);

        /** @var Response $response */
        $response = $next($request);

        $this->scrub($request);

        return $response;
    }

    private function scrub(Request $request): void
    {
        $request->headers->set('Authorization', self::REDACTED);
        $request->headers->set('Proxy-Authorization', self::REDACTED);
        $request->headers->set('X-Api-Key', self::REDACTED);
        $request->headers->set('Cookie', self::REDACTED);

        foreach (self::SERVER_KEYS as $key) {
            if ($request->server->has($key)) {
                $request->server->set($key, self::REDACTED);
            }

            if (array_key_exists($key, $_SERVER)) {
                $_SERVER[$key] = self::REDACTED;
            }
        }
    }
}
