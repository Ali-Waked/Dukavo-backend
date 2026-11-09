<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;

/**
 * Rendering a campaign's copy before it exists.
 *
 * The composer previews `{name}` and `{workspace}` against a real merchant so
 * an operator sees what will actually arrive instead of the raw template.
 */
class PreviewNotificationCampaignRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
