<?php

declare(strict_types=1);

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| `routes/api.php` is registered with the `/api` prefix by the framework, so the
| URLs below appear under /api. The versioned surface lives in `routes/api/v1`
| and is required from here rather than loaded automatically, because only this
| file decides which middleware a group receives.
|
| Public surface, in full:
|   GET  /api/health
|   GET  /api/v1/webhooks/whatsapp        Meta handshake
|   POST /api/v1/webhooks/whatsapp        inbound messages and receipts
|   /api/v1/auth/*                        Fortify (login, logout, passwords)
|   GET  /api/v1/public/features          what the product does (config)
|   GET  /api/v1/public/pricing           marketing pricing plans
|   GET  /api/v1/public/faq               marketing FAQ
|   POST /api/v1/public/waitlist          join the waitlist (throttled)
|   POST /api/v1/public/contact           contact form (throttled)
|   POST /api/v1/public/newsletter/subscribe    subscribe to the newsletter (throttled)
|   POST /api/v1/public/newsletter/unsubscribe  leave the newsletter (throttled)
|
| Everything else is authenticated by Sanctum and, for tenant data, carries the
| `tenant` middleware.
|
*/

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function (): void {
    require __DIR__.'/api/v1.php';
});
