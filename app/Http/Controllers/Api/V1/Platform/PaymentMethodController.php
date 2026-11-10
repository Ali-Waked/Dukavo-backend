<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Payments\StorePaymentMethodRequest;
use App\Http\Requests\Platform\Payments\UpdatePaymentMethodRequest;
use App\Models\PaymentMethodDefinition;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The customer-facing method catalogue.
 *
 * Codes such as `card`, `knet`, `benefit` and `apple_pay` are shared by every
 * provider. A disabled definition is removed from every provider's checkout at
 * once, without editing each provider row.
 */
class PaymentMethodController extends Controller
{
    public function index(): JsonResponse
    {
        $methods = PaymentMethodDefinition::query()
            ->with('providerMethods')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success('', [
            'methods' => $methods->map(fn (PaymentMethodDefinition $method): array => $this->payload($method))->values()->all(),
        ]);
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $method = PaymentMethodDefinition::query()->create($request->validated());

        return ApiResponse::created('Payment method created.', $this->payload($method));
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethodDefinition $method): JsonResponse
    {
        $method->fill($request->validated())->save();

        return ApiResponse::success('Payment method updated.', $this->payload($method));
    }

    public function destroy(PaymentMethodDefinition $method): JsonResponse
    {
        if ($method->providerMethods()->exists()) {
            throw BusinessRuleViolation::because('Remove this method from every provider before deleting it.');
        }

        $method->delete();

        return ApiResponse::success('Payment method deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PaymentMethodDefinition $method): array
    {
        return [
            'id' => $method->id,
            'code' => $method->code,
            'name' => $method->name,
            'name_ar' => $method->name_ar,
            'description' => $method->description,
            'description_ar' => $method->description_ar,
            'kind' => $method->kind,
            'is_active' => (bool) $method->is_active,
            'sort_order' => (int) $method->sort_order,
            'provider_count' => $method->providerMethods->count(),
        ];
    }
}
