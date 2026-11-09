<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\NotificationPriority;
use App\Enums\NotificationSeverity;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a platform notification campaign.
 *
 * A campaign is addressed to merchants across every workspace, so there is no
 * tenant validation here: the audience rules are platform-level and validated
 * against the users/tenants tables directly. `channels` and `audience` are
 * required because a draft without a destination is not a campaign.
 */
class StoreNotificationCampaignRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'category' => ['sometimes', 'string', 'max:40', Rule::in([
                'announcements', 'orders', 'inventory', 'messages', 'approvals',
                'security', 'reports', 'product', 'billing',
            ])],
            'severity' => ['sometimes', Rule::enum(NotificationSeverity::class)],
            'priority' => ['sometimes', Rule::enum(NotificationPriority::class)],
            'action_url' => ['nullable', 'string', 'max:2048', 'regex:#^(https?://|/)#i'],
            'channels' => ['required', 'array', 'min:1', 'max:3'],
            'channels.*' => ['required', 'string', Rule::in(['in_app', 'push', 'mail'])],
            'audience' => ['required', 'array'],
            'audience.type' => ['required', 'string', Rule::in(['all', 'tenants', 'users'])],
            'audience.tenant_ids' => ['required_if:audience.type,tenants', 'array', 'max:500'],
            'audience.tenant_ids.*' => ['integer', 'exists:tenants,id'],
            'audience.user_ids' => ['required_if:audience.type,users', 'array', 'max:500'],
            'audience.user_ids.*' => ['integer', 'exists:users,id'],
            'audience.exclude_user_ids' => ['sometimes', 'array', 'max:500'],
            'audience.exclude_user_ids.*' => ['integer', 'exists:users,id'],
            'is_marketing' => ['sometimes', 'boolean'],
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'audience.type' => 'audience type',
            'audience.tenant_ids' => 'workspaces',
            'audience.user_ids' => 'recipients',
            'scheduled_at' => 'schedule time',
            'idempotency_key' => 'idempotency key',
        ];
    }
}
