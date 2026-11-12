<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Settings;

use App\Http\Requests\ApiRequest;

class ToggleFeatureFlagRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
