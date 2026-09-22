<?php

use App\Exceptions\ActiveShiftExistsException;
use App\Exceptions\ChargeAmountMismatchException;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\InvalidPasscodeException;
use App\Exceptions\NoActiveShiftException;
use App\Exceptions\OpenTicketsExistException;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Domain-rule violations (e.g. "ticket is not open") that services throw as
        // plain InvalidArgumentException, plus the typed exceptions below, all read as
        // 409 Conflict per the documented API error catalog. Passcode failures are 403.
        $conflictExceptions = [
            NoActiveShiftException::class,
            ActiveShiftExistsException::class,
            OpenTicketsExistException::class,
            ChargeAmountMismatchException::class,
            InsufficientInventoryException::class,
            InvalidArgumentException::class,
        ];

        $exceptions->render(function (Throwable $e, Request $request) use ($conflictExceptions) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            if ($e instanceof InvalidPasscodeException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                ], 404);
            }

            foreach ($conflictExceptions as $conflictException) {
                if ($e instanceof $conflictException) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                    ], 409);
                }
            }

            // Catch-all for anything Laravel/Symfony already resolved to a concrete
            // HTTP status - AuthorizationException -> AccessDeniedHttpException (403),
            // an unmatched route -> NotFoundHttpException (404), throttled requests
            // -> ThrottleRequestsException (429), etc. Laravel performs this
            // conversion before render callbacks that check the original exception
            // type run, so matching the interface (not a specific class) is what
            // actually catches these.
            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Request could not be processed.',
                ], $e->getStatusCode());
            }

            return null;
        });
    })->create();
