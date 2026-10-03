<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never flash credentials back into the session on a validation error (Account settings and login).
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $error = fn (string $code, string $message, int $status): JsonResponse => response()->json(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
        );

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? $error('unauthenticated', 'Session expired — sign in again', 401)
            : null);

        $exceptions->render(fn (TokenMismatchException $e, Request $request) => $request->is('api/*')
            ? $error('csrf_mismatch', 'Session expired — sign in again', 419)
            : null);

        // Laravel converts TokenMismatchException into an HttpException(419) before render callbacks run.
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $request->is('api/*')
            && ($e->getStatusCode() === 419 || $e->getPrevious() instanceof TokenMismatchException)
            ? $error('csrf_mismatch', 'Session expired — sign in again', 419)
            : null);

        $exceptions->render(fn (ValidationException $e, Request $request) => $request->is('api/*')
            ? $error('validation', (string) collect($e->errors())->flatten()->first(), 422)
            : null);

        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => $request->is('api/*')
            ? $error('not_found', 'Not found.', 404)
            : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? $error('not_found', 'Not found.', 404)
            : null);
    })->create();
