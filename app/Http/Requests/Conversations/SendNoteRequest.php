<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversations;

use App\Http\Requests\ApiRequest;

class SendNoteRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4000'],
        ];
    }
}
