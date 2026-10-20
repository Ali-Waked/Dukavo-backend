<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\NewsletterContentRequest;
use App\Services\Ai\Features\NewsletterAssistant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Content assistance for newsletter authoring.
 *
 * It proposes a title, excerpt and sanitised body; it never saves or publishes
 * an edition. The writer decides what to carry into the editor, and the normal
 * newsletter endpoint applies the usual sanitisation again on save.
 */
class ContentAssistantController extends Controller
{
    public function __construct(private readonly NewsletterAssistant $assistant) {}

    public function generate(NewsletterContentRequest $request): JsonResponse
    {
        $result = $this->assistant->generate(
            $request->validated('action'),
            $request->validated('brief'),
            $request->validated('audience'),
            $request->validated('body'),
            (int) $request->user()->getKey(),
        );

        return ApiResponse::success('Draft content ready for review.', $result);
    }
}
