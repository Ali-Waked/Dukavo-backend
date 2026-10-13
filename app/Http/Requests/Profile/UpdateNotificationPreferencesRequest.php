<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Http\Requests\ApiRequest;
use App\Models\User;

/**
 * Notification preference changes.
 *
 * The rules are generated from {@see User::NOTIFICATION_CHANNELS} rather than
 * hand-written, so the catalogue in the model is the only place that decides
 * which switches exist. Unknown keys are not validated, and `validated()` drops
 * them, which is what keeps an arbitrary payload from polluting the JSON column.
 */
class UpdateNotificationPreferencesRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (User::NOTIFICATION_CHANNELS as $channel => $events) {
            $rules[$channel] = ['sometimes', 'array'];

            foreach ($events as $event) {
                $rules["{$channel}.{$event}"] = ['sometimes', 'boolean'];
            }
        }

        return $rules;
    }
}
