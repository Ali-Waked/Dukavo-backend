<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\HelpArticleStatus;
use App\Enums\HelpArticleVisibility;
use App\Http\Requests\ApiRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Editing a Help Center article. Every field is `sometimes` so a partial patch
 * stays valid; the slug is only re-derived when one is actually sent, and
 * uniqueness ignores the row being edited.
 */
class UpdateHelpArticleRequest extends ApiRequest
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
        $article = $this->route('helpArticle');

        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:help_categories,id'],
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'title_ar' => ['sometimes', 'nullable', 'string', 'max:200'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:200',
                'alpha_dash',
                Rule::unique('help_articles', 'slug')->ignore($article),
            ],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:320'],
            'excerpt_ar' => ['sometimes', 'nullable', 'string', 'max:320'],
            'body' => ['sometimes', 'required', 'string', 'max:200000'],
            'body_ar' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'cover_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_cover' => ['sometimes', 'boolean'],
            'video_url' => ['sometimes', 'nullable', 'url', 'max:255', 'starts_with:https://'],
            'status' => ['sometimes', Rule::enum(HelpArticleStatus::class)],
            'visibility' => ['sometimes', Rule::enum(HelpArticleVisibility::class)],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta_title_ar' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'meta_description_ar' => ['sometimes', 'nullable', 'string', 'max:320'],
            'meta_keywords' => ['sometimes', 'nullable', 'string', 'max:500'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['integer', 'exists:help_tags,id'],
            'related_article_ids' => ['sometimes', 'array', 'max:10'],
            'related_article_ids.*' => ['integer', 'exists:help_articles,id'],
        ];
    }
}
