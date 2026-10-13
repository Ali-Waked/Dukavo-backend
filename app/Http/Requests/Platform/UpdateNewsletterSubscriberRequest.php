<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\NewsletterStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Changing a subscriber's status. The status is the only writable field: the
 * email is an identity, consent timestamps are written by the subscription
 * flow itself, and a re-subscribe always records fresh consent rather than
 * reusing the old one.
 */
class UpdateNewsletterSubscriberRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(NewsletterStatus::class)],
        ];
    }
}
