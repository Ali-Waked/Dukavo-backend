<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Resources\FaqEntryResource;
use App\Models\FaqEntry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public FAQ.
 *
 * Active entries only, in the same display order the admin interface edits.
 * The whole list is returned unpaginated: an accordion that loads one row at
 * a time is a worse experience than a single small payload.
 *
 * The response carries the standard `success` envelope to match its `features`
 * sibling in the same public group: a visitor's client should not have to
 * special-case which marketing reads acknowledge the envelope and which do not.
 */
class FaqController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(data: FaqEntryResource::collection(
            FaqEntry::query()->active()->ordered()->get(),
        ));
    }
}
