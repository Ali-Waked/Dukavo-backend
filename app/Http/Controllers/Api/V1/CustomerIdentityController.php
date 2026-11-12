<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\LinkCustomerIdentityRequest;
use App\Http\Resources\CustomerIdentityResource;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Services\Channels\CustomerIdentityResolver;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The provider identities attached to a customer.
 *
 * Listing is part of reading a customer; linking and unlinking are explicit
 * edits. Nothing here merges two customers — that remains a deliberate CRM
 * action with its own audit trail.
 */
class CustomerIdentityController extends Controller
{
    public function __construct(private readonly CustomerIdentityResolver $identities) {}

    public function index(Customer $customer): AnonymousResourceCollection
    {
        return CustomerIdentityResource::collection(
            $this->identities->identitiesFor($customer),
        );
    }

    public function store(LinkCustomerIdentityRequest $request, Customer $customer): JsonResponse
    {
        $identity = $this->identities->link(
            $customer,
            (string) $request->validated('channel'),
            (string) $request->validated('external_id'),
            array_filter([
                'username' => $request->validated('username'),
                'display_name' => $request->validated('display_name'),
                'phone' => $request->validated('phone'),
                'email' => $request->validated('email'),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );

        return ApiResponse::created('Identity linked.', new CustomerIdentityResource($identity));
    }

    public function destroy(Customer $customer, CustomerIdentity $identity): JsonResponse
    {
        abort_unless($identity->customer_id === $customer->getKey(), 404);

        $this->identities->unlink($identity);

        return ApiResponse::success('Identity unlinked.');
    }
}
