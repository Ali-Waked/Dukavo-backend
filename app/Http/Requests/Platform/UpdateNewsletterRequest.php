<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\NewsletterArticleStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Editing a newsletter edition.
 *
 * Every field is `sometimes`: the edit form submits the whole document, but the
 * request tolerates a partial patch, which keeps it usable by a future inline
 * edit without inventing a second endpoint. `slug` is only re-derived when the
 * admin actually sends one — the public URL is stable by default and changing it
 * is an explicit act, never a side effect of retitling.
 *
 * Uniqueness ignores the edition being edited, so re-saving without touching the
 * slug stays valid.
 */
class UpdateNewsletterRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('slug')) {
            $this->merge(['slug' => Str::slug($this->string('slug')->toString())]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $newsletter = $this->route('newsletter');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:200',
                'alpha_dash',
                Rule::unique('newsletters', 'slug')->ignore($newsletter),
            ],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:320'],
            'body' => ['sometimes', 'required', 'string', 'max:200000'],
            'cover_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_cover' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(NewsletterArticleStatus::class)],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
        ];
    }
}
