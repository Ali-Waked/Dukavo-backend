<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use App\Http\Requests\ApiRequest;

/**
 * Submitting the public contact form.
 *
 * Carriage returns and line feeds are stripped from every single line field
 * before validation: the subject is echoed into an outgoing email subject
 * header, and a raw newline there would be header injection. The `website`
 * honeypot is excluded from the rules for the same reason as the waitlist
 * request — a filled honeypot is silently dropped by the controller.
 */
class StoreContactMessageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $strip = static fn (mixed $value): ?string => $value === null
            ? null
            : trim(str_replace(["\r", "\n"], ' ', (string) $value));

        $this->merge([
            'name' => $this->has('name') ? $strip($this->input('name')) : null,
            'email' => $this->has('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
            'subject' => $this->has('subject') ? $strip($this->input('subject')) : null,
            'message' => $this->filled('message') ? trim((string) $this->input('message')) : null,
        ]);
    }
}
