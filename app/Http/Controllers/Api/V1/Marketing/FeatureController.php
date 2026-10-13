<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The marketing feature list.
 *
 * Served from `config/dukavo.php` rather than from a table: the features
 * describe what the product *does*, they ship with the code, and they must
 * never drift from it. A capability that does not exist yet is declared with
 * `status: coming_soon` where it is defined — never invented at runtime.
 *
 * Hand-rolled envelope (like the permission catalogue) because this is static
 * configuration, not a resource collection.
 */
class FeatureController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'features' => config('dukavo.marketing.features'),
            ],
        ]);
    }
}
