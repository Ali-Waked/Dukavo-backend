<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a public content page.
 *
 * `sections` is replaced wholesale rather than patched row by row: the admin
 * editor always has the whole ordered list in memory, so sending it whole keeps
 * the stored structure and the screen from drifting apart.
 *
 * Each section is validated structurally. `id` must be an anchor-safe slug and
 * unique within the page (it becomes the heading's `id`), `layout` must be one
 * the renderer knows, and `body` is plain text with a generous, but bounded,
 * length — a section that cannot fit is a sign the page should be split, not a
 * reason to raise the cap.
 */
class UpdateContentPageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'intro' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sections' => ['sometimes', 'array', 'max:60'],
            'sections.*.id' => ['required', 'string', 'max:80', 'distinct', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'sections.*.title' => ['required', 'string', 'max:200'],
            'sections.*.layout' => ['sometimes', 'string', Rule::in(['prose', 'cards', 'steps', 'cta'])],
            'sections.*.body' => ['present', 'string', 'max:8000'],
            'active' => ['sometimes', 'boolean'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
        ];
    }
}
