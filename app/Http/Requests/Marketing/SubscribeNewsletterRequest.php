<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use App\Http\Requests\ApiRequest;

/**
 * Joining the newsletter.
 *
 * The email is normalised before validation so `  FirstName@Example.COM ` is
 * validated, stored and de-duplicated as `firstname@example.com`. Submitting
 * the form *is* the consent — the service records `consented_at` from this
 * request — and the `website` honeypot field is deliberately not in the rules:
 * a bot that fills it is answered with the same success payload and the entry
 * is dropped by the controller.
 */
class SubscribeNewsletterRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:190'],
            'source' => ['nullable', 'string', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->has('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
            'source' => $this->filled('source') ? trim((string) $this->input('source')) : null,
        ]);
    }
}
