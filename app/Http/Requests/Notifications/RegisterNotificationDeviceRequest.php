<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * A push installation registering with Laravel.
 *
 * The token is the only sensitive field and is never echoed back by the API.
 * `device_id` is the app's own stable installation identifier, which lets a
 * rotated token replace the old one for the same phone instead of leaving two
 * rows that both receive the same alert.
 */
class RegisterNotificationDeviceRequest extends ApiRequest
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
        return [
            'token' => ['required', 'string', 'min:20', 'max:4096'],
            'device_id' => ['nullable', 'string', 'max:191'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web', 'unknown'])],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'locale' => ['nullable', 'string', 'max:12', Rule::in(['en', 'ar'])],
        ];
    }
}
