<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\ConnectWhatsappAccountRequest;
use App\Http\Requests\Channels\UpdateWhatsappAccountRequest;
use App\Http\Resources\WhatsappAccountResource;
use App\Models\WhatsappAccount;
use App\Services\Channels\WhatsApp\WhatsAppAccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * WhatsApp Cloud API accounts.
 *
 * A token is write-only: it is accepted once, stored encrypted, and never
 * returned by a resource. Verification is a separate, explicit action so that
 * saving credentials does not silently start calling Meta.
 */
class WhatsappAccountController extends Controller
{
    public function __construct(private readonly WhatsAppAccountService $accounts) {}

    public function index(): AnonymousResourceCollection
    {
        return WhatsappAccountResource::collection(
            WhatsappAccount::query()
                ->with('store')
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(ConnectWhatsappAccountRequest $request): JsonResponse
    {
        return ApiResponse::created(
            'WhatsApp account connected. Verify it to start sending.',
            new WhatsappAccountResource($this->accounts->connect($request->validated())),
        );
    }

    public function show(WhatsappAccount $whatsappAccount): WhatsappAccountResource
    {
        return new WhatsappAccountResource($whatsappAccount->load('store'));
    }

    public function update(UpdateWhatsappAccountRequest $request, WhatsappAccount $whatsappAccount): WhatsappAccountResource
    {
        return new WhatsappAccountResource(
            $this->accounts->update($whatsappAccount, $request->validated()),
        );
    }

    public function verify(WhatsappAccount $whatsappAccount): WhatsappAccountResource
    {
        return new WhatsappAccountResource($this->accounts->verify($whatsappAccount));
    }

    public function disconnect(WhatsappAccount $whatsappAccount): WhatsappAccountResource
    {
        return new WhatsappAccountResource($this->accounts->disconnect($whatsappAccount));
    }

    public function destroy(WhatsappAccount $whatsappAccount): Response
    {
        $this->accounts->delete($whatsappAccount);

        return response()->noContent();
    }
}
