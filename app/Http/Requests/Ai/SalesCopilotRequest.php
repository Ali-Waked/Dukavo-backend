<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

/**
 * Optional steer for the sales copilot. The conversation itself comes from the
 * route, so the only input is the agent's instruction.
 */
class SalesCopilotRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'instruction' => ['nullable', 'string', 'max:1500'],
        ];
    }
}
