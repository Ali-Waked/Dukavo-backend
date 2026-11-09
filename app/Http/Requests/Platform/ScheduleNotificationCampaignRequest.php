<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;

/**
 * Moving a campaign to a future delivery time.
 *
 * A separate endpoint from update because scheduling is a lifecycle action,
 * not an edit: it changes status as well as the timestamp, and the service
 * audits it as a scheduling event.
 */
class ScheduleNotificationCampaignRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['scheduled_at' => 'schedule time'];
    }
}
