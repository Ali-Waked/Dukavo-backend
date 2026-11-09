<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\NotificationPriority;
use App\Enums\NotificationSeverity;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a draft or scheduled campaign.
 *
 * Every field is optional: the client sends only what changed. The service
 * refuses the edit entirely once the campaign has started publishing, so this
 * request does not need to duplicate the lifecycle rule.
 */
class UpdateNotificationCampaignRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:160'],
            'body' => ['sometimes', 'string', 'max:5000'],
            'category' => ['sometimes', 'string', 'max:40', Rule::in([
                'announcements', 'orders', 'inventory', 'messages', 'approvals',
                'security', 'reports', 'product', 'billing',
            ])],
            'severity' => ['sometimes', Rule::enum(NotificationSeverity::class)],
            'priority' => ['sometimes', Rule::enum(NotificationPriority::class)],
            'action_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:#^(https?://|/)#i'],
            'channels' => ['sometimes', 'array', 'min:1', 'max:3'],
            'channels.*' => ['required', 'string', Rule::in(['in_app', 'push', 'mail'])],
            'audience' => ['sometimes', 'array'],
            'audience.type' => ['required_with:audience', 'string', Rule::in(['all', 'tenants', 'users'])],
            'audience.tenant_ids' => ['required_if:audience.type,tenants', 'array', 'max:500'],
            'audience.tenant_ids.*' => ['integer', 'exists:tenants,id'],
            'audience.user_ids' => ['required_if:audience.type,users', 'array', 'max:500'],
            'audience.user_ids.*' => ['integer', 'exists:users,id'],
            'audience.exclude_user_ids' => ['sometimes', 'array', 'max:500'],
            'audience.exclude_user_ids.*' => ['integer', 'exists:users,id'],
            'is_marketing' => ['sometimes', 'boolean'],
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
