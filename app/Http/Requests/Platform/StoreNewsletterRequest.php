<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\NewsletterArticleStatus;
use App\Http\Requests\ApiRequest;
use App\Services\Marketing\NewsletterContentService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creating a newsletter edition.
 *
 * `body` is required and bounded generously: it is the article itself, and a
 * limit that fits only a teaser would be the wrong limit. The server sanitises
 * it on write ({@see NewsletterContentService}), so the
 * request only guarantees it is a string of a sane maximum size.
 *
 * `slug` is the public URL and must be unique. It is derived from the title when
 * omitted — which succeeds for Latin titles and yields an empty string for
 * Arabic ones, so `required` rejects the submission and the admin is asked for
 * an explicit ASCII identifier instead of being given a misleading transliteration.
 */
class StoreNewsletterRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('slug')) {
            $this->merge(['slug' => Str::slug($this->string('slug')->toString())]);

            return;
        }

        $title = $this->string('title')->toString();

        // Only an ASCII title yields an honest automatic slug. A non-Latin title
        // is left without one so the `required` rule asks the admin for an
        // explicit identifier, rather than persisting a crude transliteration
        // ("مرحبا بالعالم" -> "mrhba-balaaalm") that would be mistaken for a real
        // URL.
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
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', 'alpha_dash', 'unique:newsletters,slug'],
            'excerpt' => ['nullable', 'string', 'max:320'],
            'body' => ['required', 'string', 'max:200000'],
            'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'status' => ['sometimes', Rule::enum(NewsletterArticleStatus::class)],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:320'],
        ];
    }
}
