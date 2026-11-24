<?php

use App\Http\ProblemDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        // The v1 API is JSON only and compares user ids byte for byte. Laravel's
        // global string trimming would silently change allow-list entries such
        // as " alice" and would trim invalid flag keys into valid ones.
        $middleware->trimStrings(except: [
            static fn (Request $request): bool => $request->is('v1/*'),
        ]);

        $middleware->convertEmptyStringsToNull(except: [
            static fn (Request $request): bool => $request->is('v1/*'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ProblemDetails::response(
                422,
                'One or more fields are invalid.',
                $exception->errors(),
            );
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ProblemDetails::response(404, 'The requested resource does not exist.');
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ProblemDetails::response(404, 'The requested resource does not exist.');
        });

        $exceptions->render(function (MethodNotAllowedHttpException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ProblemDetails::response(405, 'That method is not allowed on this path.');
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ProblemDetails::response(401, 'A valid credential is required.');
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ProblemDetails::response(403, 'The credential is not allowed to do that.');
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            $message = trim($exception->getMessage());

            return ProblemDetails::response(
                $exception->getStatusCode(),
                $message !== '' ? $message : null,
                null,
                $exception->getHeaders(),
            );
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('v1/*') || app()->environment('testing')) {
                return null;
            }

            report($exception);

            return ProblemDetails::response(500, 'An unexpected error occurred.');
        });
    })->create();
