<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\HelpArticleStatus;
use App\Enums\HelpArticleVisibility;
use App\Http\Requests\ApiRequest;
use App\Services\Help\HelpContentService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creating a Help Center article.
 *
 * `body` is required and bounded generously: it is the guide itself. The
 * server sanitises it on write ({@see HelpContentService}),
 * so the request only guarantees it is a string of a sane maximum size.
 *
 * `slug` follows the newsletter rule: derived from the title when omitted,
 * which succeeds for Latin titles and yields an empty string for Arabic ones,
 * so `required` rejects the submission and the admin is asked for an explicit
 * ASCII identifier instead of a misleading transliteration.
 */
class StoreHelpArticleRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('slug')) {
            $this->merge(['slug' => Str::slug($this->string('slug')->toString())]);

            return;
        }

        $title = $this->string('title')->toString();

        // Only an ASCII title yields an honest automatic slug.
        if ($title !== '' && mb_check_encoding($title, 'ASCII')) {
            $this->merge(['slug' => Str::slug($title)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:help_categories,id'],
            'title' => ['required', 'string', 'max:200'],
            'title_ar' => ['nullable', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', 'alpha_dash', 'unique:help_articles,slug'],
            'excerpt' => ['nullable', 'string', 'max:320'],
            'excerpt_ar' => ['nullable', 'string', 'max:320'],
            'body' => ['required', 'string', 'max:200000'],
            'body_ar' => ['nullable', 'string', 'max:200000'],
            'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'video_url' => ['nullable', 'url', 'max:255', 'starts_with:https://'],
            'status' => ['sometimes', Rule::enum(HelpArticleStatus::class)],
            'visibility' => ['sometimes', Rule::enum(HelpArticleVisibility::class)],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_title_ar' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'meta_description_ar' => ['nullable', 'string', 'max:320'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['integer', 'exists:help_tags,id'],
            'related_article_ids' => ['sometimes', 'array', 'max:10'],
            'related_article_ids.*' => ['integer', 'exists:help_articles,id'],
        ];
    }
}
