<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The credentials were correct, but the account is suspended.
 *
 * Throwing only after the password check keeps the public failure shape
 * identical to a wrong password for anyone who does not know it. A caller who
 * does prove ownership is told the real reason so the SPA can show the account
 * status page instead of claiming their password is wrong.
 */
class AccountSuspendedException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('This account is suspended. Contact support if you believe this is a mistake.'),
            'code' => 'account_suspended',
        ], 403);
    }
}
