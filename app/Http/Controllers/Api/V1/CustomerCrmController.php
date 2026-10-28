<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\UpdateCustomerSummaryRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\CustomerSummaryResource;
use App\Models\Customer;
use App\Services\Crm\CustomerSummaryService;
use App\Services\Crm\CustomerTimelineService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerCrmController extends Controller
{
    public function __construct(
        private readonly CustomerTimelineService $timeline,
        private readonly CustomerSummaryService $summaries,
    ) {}

    public function timeline(Request $request, Customer $customer): JsonResponse
    {
        $limit = min((int) $request->integer('limit', 50), 100);

        return ApiResponse::success('', [
            'items' => $this->timeline->getTimeline($customer, $limit),
        ]);
    }

    public function overview(Request $request, Customer $customer): JsonResponse
    {
        $customer->load(['addresses', 'store']);
        $customer->loadCount('orders');
        $customer->loadCount('conversations');
        $customer->loadCount('memories');

        $stats = $this->timeline->getStats($customer);
        $summary = $this->summaries->getOrCreate($customer);

        return ApiResponse::success('', [
            'customer' => new CustomerResource($customer),
            'stats' => $stats,
            'summary' => new CustomerSummaryResource($summary->load(['generator', 'editor'])),
            'last_interaction_at' => $stats['last_interaction_at'] ?? null,
        ]);
    }

    public function summary(Request $request, Customer $customer): JsonResponse
    {
        $summary = $this->summaries->getOrCreate($customer);

        return ApiResponse::success('', new CustomerSummaryResource($summary->load(['generator', 'editor'])));
    }

    public function refreshSummary(Request $request, Customer $customer): JsonResponse
    {
        $summary = $this->summaries->refresh($customer, $request->user());

        return ApiResponse::success('Customer summary refreshed.', new CustomerSummaryResource($summary->load(['generator', 'editor'])));
    }

    public function updateSummary(UpdateCustomerSummaryRequest $request, Customer $customer): JsonResponse
    {
        $summary = $this->summaries->getOrCreate($customer);
        $summary = $this->summaries->update($summary, $request->validated(), $request->user());

        return ApiResponse::success('Customer summary updated.', new CustomerSummaryResource($summary->load(['generator', 'editor'])));
    }
}
