<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;

/**
 * RFC 9457 problem details used by every error response under /v1.
 */
final class ProblemDetails
{
    /**
     * @var array<int, string>
     */
    private const TITLES = [
        400 => 'Bad request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not found',
        405 => 'Method not allowed',
        409 => 'Conflict',
        413 => 'Payload too large',
        415 => 'Unsupported media type',
        422 => 'Validation failed',
        429 => 'Too many requests',
        500 => 'Internal server error',
    ];

    /**
     * @var array<int, string>
     */
    private const TYPES = [
        400 => 'bad-request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not-found',
        405 => 'method-not-allowed',
        409 => 'conflict',
        413 => 'payload-too-large',
        415 => 'unsupported-media-type',
        422 => 'validation-error',
        429 => 'too-many-requests',
        500 => 'internal-error',
    ];

    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, string>  $headers
     */
    public static function response(
        int $status,
        ?string $detail = null,
        ?array $errors = null,
        array $headers = [],
    ): JsonResponse {
        $title = self::TITLES[$status] ?? 'Error';
        $type = self::TYPES[$status] ?? 'error';

        $body = [
            'type' => "https://flagpost.dev/problems/{$type}",
            'title' => $title,
            'status' => $status,
            'detail' => $detail ?? $title,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        $headers['Content-Type'] = 'application/problem+json';

        return response()->json($body, $status, $headers, JSON_UNESCAPED_SLASHES);
    }
}
