<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;

/**
 * Creating an FAQ entry. The answer accepts multi line markdown-ish text up
 * to a size that still renders on one screen; the question stays short because
 * it is what the accordion shows as its header.
 */
class StoreFaqEntryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:200'],
            'answer' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
            'active' => ['boolean'],
        ];
    }
}
