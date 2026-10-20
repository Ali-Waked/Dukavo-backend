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

    /**
     * The platform-level WhatsApp configuration, without any of its secrets.
     *
     * The SPA needs to explain *why* the channel is not working — a missing App
     * Secret refuses every inbound delivery, an absent platform token means a new
     * tenant has to supply their own — and it can only do that if it can ask. The
     * answer is booleans plus the two public webhook URLs: never the token, the
     * App Secret or the verify token, masked or otherwise. A masked secret is
     * still a secret leaving the server and landing in a browser cache, a proxy
     * log and a support screenshot.
     */
    public function configuration(): JsonResponse
    {
        $whatsapp = (array) config('services.whatsapp');
        $appSecret = $whatsapp['app_secret'] ?? null;

        return ApiResponse::success('WhatsApp configuration.', [
            'api_version' => $whatsapp['cloud_api_version'] ?? null,
            'api_host' => $whatsapp['api_base_url'] ?? null,
            'configured' => [
                'phone_number_id' => filled($whatsapp['phone_number_id'] ?? null),
                'business_account_id' => filled($whatsapp['business_account_id'] ?? null),
                'access_token' => filled($whatsapp['cloud_api_token'] ?? null),
                'webhook_verify_token' => filled($whatsapp['webhook_verify_token'] ?? null),
                'app_secret' => filled($appSecret),
            ],
            'webhook' => [
                'verify_url' => route('webhooks.whatsapp.verify'),
                'events_url' => route('webhooks.whatsapp.events'),
                // Inbound deliveries are refused outright until the App Secret is
                // set, so this is the difference between "nothing arrives" and
                // "something is misconfigured at Meta".
                'signature_validation' => filled($appSecret) ? 'enforced' : 'not_configured',
            ],
        ]);
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
