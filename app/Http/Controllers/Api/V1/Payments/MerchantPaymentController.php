<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\RefundPaymentRequest;
use App\Http\Resources\Payments\PaymentRefundResource;
use App\Http\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Services\Payments\PaymentRefundService;
use App\Services\Payments\PaymentStatusService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant payment history, provider synchronisation and refunds.
 *
 * Every query is tenant-scoped by the model's global scope; a payment from
 * another merchant is a 404 through route model binding, not a filtered list.
 * Refunds are bounded by the original captured charge inside the service.
 */
class MerchantPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentStatusService $statuses,
        private readonly PaymentRefundService $refunds,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->with(['provider', 'order'])
            ->when($request->query('store_id') !== null, fn ($query) => $query->where('store_id', (int) $request->query('store_id')))
            ->when($request->query('order_id') !== null, fn ($query) => $query->where('order_id', (int) $request->query('order_id')))
            ->when($request->query('status') !== null, fn ($query) => $query->where('status', (string) $request->query('status')))
            ->latest()
            ->paginate((int) min(100, max(1, (int) $request->query('per_page', 20))));

        return ApiResponse::success('', PaymentResource::collection($payments));
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->loadMissing(['provider', 'order', 'transactions', 'refunds']);

        return ApiResponse::success('', new PaymentResource($payment));
    }

    /**
     * Explicitly ask the provider for its current answer. This is a merchant
     * action, not something a polling storefront page may trigger.
     */
    public function sync(Payment $payment): JsonResponse
    {
        $payment = $this->statuses->sync($payment);
        $payment->loadMissing(['provider', 'order', 'transactions', 'refunds']);

        return ApiResponse::success('Payment status refreshed.', new PaymentResource($payment));
    }

    public function refund(RefundPaymentRequest $request, Payment $payment): JsonResponse
    {
        $refund = $this->refunds->refund(
            $payment,
            (string) $request->validated('amount'),
            $request->validated('reason'),
            $request->user(),
            $request->validated('idempotency_key'),
        );

        return ApiResponse::created(
            $refund->status->isSuccessful() ? 'Refund completed.' : 'Refund submitted.',
            new PaymentRefundResource($refund),
        );
    }
}
