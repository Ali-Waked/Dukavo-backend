<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\Customers\CustomerService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Customers and their addresses.
 *
 * A customer is tenant scoped, so the listing can only ever contain this
 * merchant's customers. Addresses are edited as a nested set rather than through
 * a separate endpoint: which address is "default" is a property of the set.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $customers = Customer::query()
            ->with('addresses')
            // Aggregates are computed in SQL rather than by loading every order:
            // a busy merchant's customer list would otherwise pull thousands of
            // order rows per page just to add up a total.
            ->withCount('orders')
            ->withSum(['orders as total_spent' => $this->paidOrders()], 'grand_total')
            ->when($request->filled('search'), fn ($query) => $query->search($request->string('search')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('store_id'), fn ($query) => $query->where('store_id', $request->integer('store_id')))
            ->orderByDesc('last_order_at')
            ->orderBy('name')
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        return ApiResponse::created('Customer created.', new CustomerResource($this->customers->create($request->validated())));
    }

    public function show(Customer $customer): CustomerResource
    {
        $customer->load(['addresses', 'store', 'orders' => fn ($query) => $query->latest('placed_at')->limit(5)]);
        $customer->loadCount('orders');
        $customer->loadSum(['orders as total_spent' => $this->paidOrders()], 'grand_total');

        return new CustomerResource($customer);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        return new CustomerResource($this->customers->update($customer, $request->validated()));
    }

    public function destroy(Customer $customer): Response
    {
        $this->customers->delete($customer);

        return response()->noContent();
    }

    /**
     * Constraint for the orders that count towards "Total Spent".
     *
     * Lifetime value is money actually collected, so an order counts only when
     * it is fully paid and was not cancelled. Refunded orders fall out through
     * the payment status; unpaid and partially paid orders are excluded because
     * `grand_total` still carries the outstanding balance and summing it would
     * overstate what the customer has paid.
     *
     * Totals are restricted to the tenant's currency so orders priced in a
     * different currency are never added together as if they were the same unit.
     *
     * @return \Closure(Builder): void
     */
    private function paidOrders(): \Closure
    {
        $currency = app(TenantContext::class)->tenant()?->currency;

        return function (Builder $query) use ($currency): void {
            $query->where('payment_status', PaymentStatus::Paid->value)
                ->where('status', '!=', OrderStatus::Cancelled->value);

            if ($currency !== null) {
                $query->where('currency', $currency);
            }
        };
    }
}
