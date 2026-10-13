<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsletterResource;
use App\Http\Resources\NewsletterSummaryResource;
use App\Models\Newsletter;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public newsletter archive.
 *
 * The blog/archive surface: published editions newest-first, and a single
 * edition by its stable slug. A draft is a 404 — never a blank document and
 * never a private preview — so a half-written edition cannot be read by a
 * visitor.
 *
 * These reads are unauthenticated and carry no tenant context, matching the rest
 * of the marketing surface. Publishing here does not send email; it only makes
 * an edition publicly readable.
 */
class NewsletterArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $editions = Newsletter::query()
            ->published()
            ->archived()
            ->paginate(min($request->integer('per_page', 9), 50))
            ->withQueryString();

        return ApiResponse::success(data: NewsletterSummaryResource::collection($editions));
    }

    public function show(string $slug): JsonResponse
    {
        $edition = Newsletter::query()
            ->published()
            ->where('slug', $slug)
            ->first();

        abort_if($edition === null, 404);

        return ApiResponse::success(data: new NewsletterResource($edition));
    }
}
