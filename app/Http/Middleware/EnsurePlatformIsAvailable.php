<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Application-level maintenance mode.
 *
 * This is deliberately not `artisan down`: the framework command writes a file
 * and has no concept of a platform role, so it would lock out the people who
 * need to switch maintenance off. Here, public and merchant traffic receives a
 * 503 with `Retry-After`, while authentication routes stay reachable and a user
 * holding any platform role continues to work. The switch is therefore always
 * reversible from the dashboard it belongs to.
 *
 * The message is bounded in the settings registry; this middleware only reads
 * it and never renders user input without the JSON envelope.
 */
class EnsurePlatformIsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('dukavo.maintenance.enabled', false)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && $user->platformRoles()->exists()) {
            return $next($request);
        }

        // Signing in, signing out and password recovery must remain possible so
        // an operator who was logged out can still reach the switch.
        if ($request->is('api/v1/auth', 'api/v1/auth/*')) {
            return $next($request);
        }

        $retryAfter = max(30, (int) config('dukavo.maintenance.retry_after_seconds', 3600));

        return response()->json([
            'success' => false,
            'message' => (string) config('dukavo.maintenance.message', 'Dukavo is undergoing scheduled maintenance. Please try again shortly.'),
        ], 503)->header('Retry-After', (string) $retryAfter);
    }
}
