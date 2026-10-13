<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use App\Http\Requests\ApiRequest;

/**
 * Leaving the newsletter.
 *
 * The same single field as subscribing, normalised the same way, so an
 * unsubscribe can always find the row a subscribe created. The endpoint answers
 * identically whether the address is known, unknown or already unsubscribed:
 * this request only validates input, it never discloses which case applied.
 */
class UnsubscribeNewsletterRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:190'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->has('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
        ]);
    }
}
