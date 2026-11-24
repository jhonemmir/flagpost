<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ProblemDetails;
use App\Support\FlagpostConfig;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the body contract: JSON only, valid JSON and at most 1 MiB.
 */
final class ValidateJsonRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $content = $request->getContent();

        if ($content === '') {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $contentType = strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0]));

        if ($contentType !== 'application/json') {
            return ProblemDetails::response(
                415,
                'Requests with a body must use application/json.',
            );
        }

        $limit = FlagpostConfig::limit('body_bytes');
        $declaredLength = (int) $request->server('CONTENT_LENGTH', 0);

        if ($declaredLength > $limit || strlen($content) > $limit) {
            return ProblemDetails::response(
                413,
                'Request body must not exceed 1 MiB.',
            );
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ProblemDetails::response(
                400,
                'The request body is not valid JSON.',
            );
        }

        // Objects and arrays are both acceptable here; field validation owns
        // the shape. A scalar would make Symfony reject the payload later
        // with an exception, so it is a client error now.
        if (! is_array($decoded)) {
            return ProblemDetails::response(
                400,
                'The request body must be a JSON object.',
            );
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
