<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use App\Enums\NotificationSeverity;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the notification centre list.
 *
 * The list is always the caller's own notifications, newest first. Read state,
 * category and severity are the only dimensions a client may narrow by; the
 * recipient and workspace remain server-side routing decisions.
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
            'category' => ['sometimes', 'string', 'max:60'],
            'severity' => ['sometimes', Rule::enum(NotificationSeverity::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function status(): string
    {
        return (string) $this->input('status', 'all');
    }

    public function category(): ?string
    {
        $category = $this->input('category');

        return is_string($category) && $category !== '' && $category !== 'all' ? $category : null;
    }

    public function severity(): ?string
    {
        $severity = $this->input('severity');

        return is_string($severity) && $severity !== '' && $severity !== 'all' ? $severity : null;
    }
}
