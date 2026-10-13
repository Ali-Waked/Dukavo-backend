<?php

declare(strict_types=1);

namespace App\Fortify;

use App\Fortify\Responses\EmailVerificationNotificationSentResponse;
use App\Fortify\Responses\FailedPasswordResetLinkRequestResponse;
use App\Fortify\Responses\FailedPasswordResetResponse;
use App\Fortify\Responses\LoginResponse;
use App\Fortify\Responses\LogoutResponse;
use App\Fortify\Responses\PasswordResetResponse;
use App\Fortify\Responses\PasswordUpdateResponse;
use App\Fortify\Responses\RegisterResponse;
use App\Fortify\Responses\SuccessfulPasswordResetLinkRequestResponse;
use App\Fortify\Responses\VerifyEmailResponse;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

/**
 * Points every Fortify response contract at Dukavo's JSON implementations.
 *
 * Doing this through the container (rather than subclassing Fortify's
 * controllers) keeps Fortify's authentication pipeline — throttling, session
 * regeneration, credential checks — completely intact.
 */
class FortifyResponseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->bind(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->bind(PasswordResetResponseContract::class, PasswordResetResponse::class);
        $this->app->bind(FailedPasswordResetResponseContract::class, FailedPasswordResetResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, SuccessfulPasswordResetLinkRequestResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, FailedPasswordResetLinkRequestResponse::class);
        $this->app->bind(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
        $this->app->bind(EmailVerificationNotificationSentResponseContract::class, EmailVerificationNotificationSentResponse::class);
        $this->app->bind(PasswordUpdateResponseContract::class, PasswordUpdateResponse::class);
    }

    public function boot(): void
    {
        //
    }
}
