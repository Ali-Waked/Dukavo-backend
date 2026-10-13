<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreNewsletterRequest;
use App\Http\Requests\Platform\UpdateNewsletterRequest;
use App\Http\Resources\NewsletterResource;
use App\Http\Resources\NewsletterSummaryResource;
use App\Models\Newsletter;
use App\Services\Marketing\NewsletterContentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Authoring newsletter editions.
 *
 * Platform-level content, so the routes sit behind the `platform` middleware and
 * outside any tenant context: a newsletter is Dukavo's own voice, not a
 * merchant's. The list and detail reads are separate resources — the table never
 * needs the article body, so it is not shipped row by row.
 *
 * Deleting an edition is allowed: nothing else references it, and its cover file
 * is removed with it. Publishing an edition only makes it visible on the public
 * archive; it does not send email.
 */
class NewsletterController extends Controller
{
    public function __construct(private readonly NewsletterContentService $newsletters) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $editions = Newsletter::query()
            ->when($request->filled('status'), fn ($query) => $query
                ->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('title', 'like', '%'.$request->string('search')->toString().'%')
                    ->orWhere('slug', 'like', '%'.$request->string('search')->toString().'%')
                    ->orWhere('excerpt', 'like', '%'.$request->string('search')->toString().'%')))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return NewsletterSummaryResource::collection($editions);
    }

    public function store(StoreNewsletterRequest $request): JsonResponse
    {
        $edition = $this->newsletters->create(
            Arr::except($request->validated(), ['cover_image']),
            $request->file('cover_image'),
        );

        return ApiResponse::created('Newsletter created.', new NewsletterResource($edition));
    }

    public function show(Newsletter $newsletter): NewsletterResource
    {
        return new NewsletterResource($newsletter);
    }

    public function update(UpdateNewsletterRequest $request, Newsletter $newsletter): JsonResponse
    {
        $edition = $this->newsletters->update(
            $newsletter,
            Arr::except($request->validated(), ['cover_image', 'remove_cover']),
            $request->file('cover_image'),
            $request->boolean('remove_cover'),
        );

        return ApiResponse::success('Newsletter updated.', new NewsletterResource($edition));
    }

    public function destroy(Newsletter $newsletter): Response
    {
        $this->newsletters->delete($newsletter);

        return response()->noContent();
    }

    /**
     * Live slug check for the edition form.
     *
     * Mirrors the product form: the client checks as the admin types so a taken
     * URL is caught before submission, and the server's unique rule remains the
     * final arbiter.
     */
    public function slugAvailability(Request $request): JsonResponse
    {
        $slug = Str::slug($request->string('slug')->toString());

        $available = $slug !== '' && ! Newsletter::query()
            ->where('slug', $slug)
            ->when($request->filled('ignore'), fn ($query) => $query->whereKeyNot($request->integer('ignore')))
            ->exists();

        return response()->json([
            'success' => true,
            'data' => ['slug' => $slug, 'available' => $available],
        ]);
    }
}
