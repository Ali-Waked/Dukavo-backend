<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerMemoryRequest;
use App\Http\Requests\Customers\UpdateCustomerMemoryRequest;
use App\Http\Resources\CustomerMemoryResource;
use App\Models\Customer;
use App\Models\CustomerMemory;
use App\Services\Customers\CustomerMemoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CustomerMemoryController extends Controller
{
    public function __construct(private readonly CustomerMemoryService $memories) {}

    public function index(Customer $customer)
    {
        $memories = CustomerMemory::query()
            ->where('customer_id', $customer->getKey())
            ->with(['creator', 'updater'])
            ->orderByDesc('updated_at')
            ->get();

        return CustomerMemoryResource::collection($memories);
    }

    public function store(StoreCustomerMemoryRequest $request, Customer $customer): JsonResponse
    {
        $memory = $this->memories->create($customer, $request->validated(), $request->user());

        return ApiResponse::created('Customer memory created.', new CustomerMemoryResource($memory->load(['creator', 'updater'])));
    }

    public function update(UpdateCustomerMemoryRequest $request, Customer $customer, CustomerMemory $memory): CustomerMemoryResource
    {
        if ((int) $memory->customer_id !== (int) $customer->getKey()) {
            abort(404);
        }

        $memory = $this->memories->update($memory, $request->validated(), $request->user());

        return new CustomerMemoryResource($memory->load(['creator', 'updater']));
    }

    public function destroy(Customer $customer, CustomerMemory $memory): Response
    {
        if ((int) $memory->customer_id !== (int) $customer->getKey()) {
            abort(404);
        }

        $this->memories->delete($memory);

        return response()->noContent();
    }
}
