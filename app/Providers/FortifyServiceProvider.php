<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\Auth\LoginResponse;
use App\Http\Responses\Auth\RegisterResponse;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Fortify registers its own routes from `boot()`, and providers boot in
     * registration order, so the opt-out has to happen in `register()` to take
     * effect before Fortify's `boot()` runs. Dukavo is an API-only application
     * consumed by a Vue SPA: the package's Blade routes are replaced by the JSON
     * routes required from `routes/api/v1.php`, which keeps a single predictable
     * auth surface at /api/v1/auth/*.
     */
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Rejecting suspended users at the credential-check stage keeps the
        // decision in one place instead of scattering status checks around.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                ->where('email', $request->input(Fortify::username()))
                ->first();

            if (! $user || ! Hash::check($request->input('password'), $user->password)) {
                return null;
            }

            return $user->status->canAuthenticate() ? $user : null;
        });

        // The SPA authenticates with a bearer token rather than a session cookie,
        // so login mints one instead of returning Fortify's `{"two_factor": false}`
        // and logout revokes it. Registration mirrors that contract so a new
        // account comes back already signed in. These are container bindings
        // rather than `Fortify::` helpers because that is how Fortify resolves
        // responses.
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);

        Fortify::loginView(fn () => null);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey)->response(fn (Request $request) => response()->json([
                'success' => false,
                'message' => __('Too many login attempts. Please try again in :seconds seconds.', [
                    'seconds' => 60,
                ]),
                'errors' => [
                    Fortify::username() => [__('Too many login attempts. Please try again shortly.')],
                ],
            ], 429));
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('dukavo.rate_limits.api', 120))
            ->by($request->user()?->id ?: $request->ip()));

        // The tenant context is released at the end of every request so a
        // long-lived worker (queue, Octane) can never inherit it.
        $this->app->terminating(fn () => $this->app->make(TenantContext::class)->forget());
    }
}
