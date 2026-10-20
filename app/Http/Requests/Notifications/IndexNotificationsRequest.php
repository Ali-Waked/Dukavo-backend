<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the notification centre list.
 *
 * Only the read state and page size are accepted: the list is always the
 * caller's own notifications, newest first, so there is nothing else to filter
 * or sort by that a client should control.
 */
final class IndexNotificationsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['all', 'unread', 'read'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function status(): string
    {
        return (string) $this->input('status', 'all');
    }
}
