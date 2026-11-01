<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreContentImageRequest;
use App\Services\Marketing\NewsletterContentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Inline images for long-form articles.
 *
 * The browser uploads the file here, the server stores it on Laravel's public
 * disk, and the editor embeds the returned URL. Nothing is ever sent to a
 * third-party image host, and the body never carries a base64 payload: the
 * stored HTML only references a path the platform owns and can delete.
 */
class BlogContentImageController extends Controller
{
    public function __construct(private readonly NewsletterContentService $content) {}

    public function store(StoreContentImageRequest $request): JsonResponse
    {
        $image = $request->file('image');

        if ($image === null) {
            return ApiResponse::error('No image was uploaded.', 422);
        }

        return ApiResponse::created(
            'Image uploaded.',
            $this->content->storeInlineImage($image),
        );
    }
}
