<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stores\ConvertStoreCurrencyRequest;
use App\Http\Resources\CurrencyConversionResource;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\Currency\CurrencyCatalog;
use App\Services\Currency\StoreCurrencyService;
use App\Support\ApiResponse;
use App\Support\PreferredLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store base-currency configuration.
 *
 * Reads are cheap and safe; the write is a financial operation and is therefore
 * explicit, confirmed and idempotent. Exchange rates are fetched here, on the
 * server, never by the SPA.
 */
class StoreCurrencyController extends Controller
{
    public function __construct(
        private readonly StoreCurrencyService $currencies,
        private readonly CurrencyCatalog $catalog,
    ) {}

    /**
     * The supported currencies, for the selector.
     *
     * Names and symbols are resolved for the request language (`?locale=`,
     * then `Accept-Language`) so an Arabic merchant sees Arabic names without
     * translating a catalogue on the client.
     */
    public function options(Request $request): JsonResponse
    {
        return ApiResponse::success(data: [
            'default' => $this->catalog->default(),
            'locale' => PreferredLocale::from($request),
            'currencies' => $this->catalog->options(PreferredLocale::from($request)),
        ]);
    }

    /**
     * Current currency plus recent conversion receipts.
     */
    public function show(Store $store): JsonResponse
    {
        return ApiResponse::success(data: [
            'store' => new StoreResource($store),
            'history' => $this->currencies->history($store),
        ]);
    }

    /**
     * Preview the rate and the affected rows before the merchant confirms.
     */
    public function preview(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
        ]);

        return ApiResponse::success(data: $this->currencies->preview($store, (string) $request->string('currency')));
    }

    /**
     * Perform the conversion.
     *
     * The client sends the idempotency key and reuses it if the response is
     * lost, so a network retry returns the original receipt instead of
     * converting the same catalogue twice.
     */
    public function convert(ConvertStoreCurrencyRequest $request, Store $store): JsonResponse
    {
        $target = strtoupper($request->string('currency')->toString());
        $key = $request->string('idempotency_key')->toString();

        $conversion = $this->currencies->convert(
            $store,
            $target,
            $key,
            $request->user()?->getKey(),
            ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
        );

        return ApiResponse::success('Store currency converted.', [
            'store' => new StoreResource($store->refresh()),
            'conversion' => new CurrencyConversionResource($conversion),
        ]);
    }
}
