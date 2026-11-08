<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Currency\CurrencyCatalog;
use App\Support\ApiResponse;
use App\Support\PreferredLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Currency metadata that does not belong to a workspace.
 *
 * Profile preferences, platform-level screens and any other personal surface
 * need the same ISO 4217 catalogue as the merchant settings, but they must not
 * be scoped to an `X-Tenant` header. This endpoint is therefore deliberately
 * outside the tenant group; it exposes presentation metadata only and never
 * touches a store or an exchange rate.
 */
class CurrencyController extends Controller
{
    public function __construct(private readonly CurrencyCatalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $locale = PreferredLocale::from($request);

        return ApiResponse::success(data: [
            'default' => $this->catalog->default(),
            'locale' => $locale,
            'currencies' => $this->catalog->options($locale),
        ]);
    }
}
