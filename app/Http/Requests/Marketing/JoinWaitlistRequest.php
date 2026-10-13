<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use App\Http\Requests\ApiRequest;

/**
 * Joining the waitlist.
 *
 * The email is normalised before validation so `  FirstName@Example.COM ` is
 * validated, stored and de-duplicated as `firstname@example.com` — one address,
 * one row, whatever the client sends. The `website` honeypot field is
 * deliberately *not* in the rules: a bot that fills it is answered with the
 * same success payload as everybody else and the entry is dropped by the
 * controller, so the bot learns nothing.
 */
class JoinWaitlistRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'business_type' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'business_type' => 'business type',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->has('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
            'name' => $this->filled('name') ? trim((string) $this->input('name')) : null,
            'business_type' => $this->filled('business_type') ? trim((string) $this->input('business_type')) : null,
            'source' => $this->filled('source') ? trim((string) $this->input('source')) : null,
        ]);
    }
}
