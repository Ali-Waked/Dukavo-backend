<?php

use App\Exceptions\BusinessRuleViolation;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlatformAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveOptionalTenant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ThrottleRequestsException;

/**
 * Every error renderer below is limited to the API surface: the same exceptions
 * on a web route keep the framework's default rendering.
 */
$isApi = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This application only serves JSON to the Vue SPA, so unauthenticated
        // requests get a 401 response instead of a redirect to a "login" route.
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        // Sanctum SPA authentication: the browser session is used when the
        // request comes from a first-party domain with the CSRF cookie, and a
        // bearer token otherwise.
        $middleware->statefulApi();
        $middleware->throttleApi('api');

        // The tenant must be resolved *before* route model binding runs: every
        // tenant model fails closed through `TenantScope`, so binding `{order}`
        // or `{conversation}` against an unresolved context would 404 every
        // legitimate request. Laravel only reorders middleware that appears in
        // the priority list, so this has to be declared, not merely aliased.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveTenant::class,
        );

        $middleware->alias([
            // Resolves the tenant from the `X-Tenant` header (validated against
            // the user's roles) or the user's only tenant, then binds it.
            'tenant' => ResolveTenant::class,
            // The same resolution as `tenant`, but a request that cannot resolve
            // one continues without it. Used by the boot endpoints, which must
            // describe users who belong to no tenant or to several.
            'tenant.optional' => ResolveOptionalTenant::class,
            // `can.access:products.update` — any one of the listed permissions
            // grants access. Policies still guard record level access.
            'permission' => EnsurePermission::class,
            // Rejects accounts that may no longer sign in, on every
            // authenticated request rather than only at login.
            'active' => EnsureUserIsActive::class,
            // Platform operators only; must be used without the tenant alias.
            'platform' => EnsurePlatformAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($isApi): void {
        $exceptions->shouldRenderJsonWhen($isApi);

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Authentication is required.',
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'This action is unauthorized.',
            ], 403);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->validator->errors()->first() ?: 'The given data was invalid.',
                'errors' => $exception->errors(),
            ], 422);
        });

        $exceptions->render(function (BusinessRuleViolation $exception, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'The requested action is not permitted.',
            ], 422);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            // Route model binding hides "does this row exist in another tenant?"
            // behind a plain 404, which is exactly what a tenant-scoped API
            // should answer.
            return response()->json([
                'success' => false,
                'message' => 'The requested resource was not found.',
            ], 404);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please slow down and try again shortly.',
            ], 429, $exception->getHeaders());
        });

        /*
         * Deliberate HTTP failures. By the time Laravel renders, an
         * AuthorizationException has already become an AccessDeniedHttpException,
         * so matching on AuthorizationException alone would let these fall through
         * to the catch-all below and be reported as a server error. Every
         * `abort()` and every framework refusal is answered with the same
         * envelope here.
         */
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $status = $exception->getStatusCode();

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: (Response::$statusTexts[$status] ?? 'The request could not be completed.'),
            ], $status, $exception->getHeaders());
        });

        // Anything unexpected is reported through the log channel and answered
        // with a generic message: internals never reach the client.
        // A middleware or package that throws a finished response (Fortify's rate
        // limiter does) must keep it: rendering it as a 500 would tell the client
        // "retry" when the real answer is "wait".
        $exceptions->render(function (HttpResponseException $exception, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return $exception->getResponse();
        });

        $exceptions->render(function (Throwable $exception, Request $request) use ($isApi) {
            if (! $isApi($request) || $exception instanceof HttpExceptionInterface) {
                return null;
            }

            $payload = ['success' => false, 'message' => 'Something went wrong. Please try again.'];

            if (config('app.debug')) {
                $payload['exception'] = $exception::class;
                $payload['detail'] = $exception->getMessage();
            }

            return response()->json($payload, 500);
        });
    })->create();
