<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Enums\WaitlistStatus;
use App\Http\Requests\Marketing\JoinWaitlistRequest;
use App\Models\WaitlistEntry;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Waitlist signups from the public marketing site.
 *
 * The only interesting case is "the same email twice": two tabs, a retried
 * request, or a visitor who simply forgot. The database's unique index is the
 * single arbiter — a lost race throws a `UniqueConstraintViolationException`,
 * which is caught and answered with the existing row, so the caller can treat
 * every outcome identically and never disclose whether the address was known.
 */
class WaitlistService
{
    /**
     * Record a signup. `$attributes` is validated, already-normalised input
     * from {@see JoinWaitlistRequest}.
     */
    public function join(array $attributes): WaitlistEntry
    {
        try {
            return WaitlistEntry::query()->create([
                'email' => $attributes['email'],
                'name' => $attributes['name'] ?? null,
                'business_type' => $attributes['business_type'] ?? null,
                'source' => $attributes['source'] ?? null,
                'status' => WaitlistStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            return WaitlistEntry::query()
                ->where('email', $attributes['email'])
                ->firstOrFail();
        }
    }
}
