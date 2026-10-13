<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\SendMessageRequest;
use App\Http\Requests\Conversations\StartConversationRequest;
use App\Http\Requests\Conversations\UpdateConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Conversations\ConversationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The messaging inbox.
 *
 * Replying hands the message to the channel gateway and only records it locally
 * once the provider has accepted it, so the thread never claims to contain a
 * message the customer did not actually receive.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly WhatsAppGateway $gateway,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = Conversation::query()
            ->with(['customer', 'assignee', 'latestMessage'])
            ->when($request->filled('status'), fn ($query) => $query->withStatus($request->string('status')->toString()))
            ->when($request->boolean('mine'), fn ($query) => $query->where('assigned_to', $request->user()->getKey()))
            ->when($request->filled('channel'), fn ($query) => $query->where('channel', $request->string('channel')->toString()))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 20), 100))
            ->withQueryString();

        return ConversationResource::collection($conversations);
    }

    public function show(Conversation $conversation): ConversationResource
    {
        $conversation->load(['customer.addresses', 'assignee', 'store']);

        return new ConversationResource($conversation);
    }

    /**
     * Open a thread proactively (an operator starting a conversation, or a
     * campaign seeding one). Idempotent on channel + channel account: opening a
     * thread that already exists returns the existing one instead of a duplicate.
     */
    public function store(StartConversationRequest $request): JsonResponse
    {
        $customer = Customer::query()->findOrFail($request->validated('customer_id'));

        $conversation = $this->conversations->openForCustomer(
            $customer,
            $request->validated('channel'),
            $request->validated('channel_account'),
            $request->safe()->only(['store_id', 'priority']),
        );

        if ($request->filled('body')) {
            $this->conversations->addMessage(
                $conversation,
                MessageDirection::from($request->validated('direction', MessageDirection::Outbound->value)),
                [
                    'user_id' => $request->user()->getKey(),
                    'body' => $request->validated('body'),
                    'external_id' => $request->validated('external_id'),
                ],
            );
        }

        return ApiResponse::created('Conversation started.', new ConversationResource($conversation->refresh()));
    }

    /**
     * The message history of one thread.
     */
    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        return MessageResource::collection(
            $conversation->messages()
                ->with('author')
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->paginate(min($request->integer('per_page', 30), 100))
                ->withQueryString()
        );
    }

    /**
     * Reply on the channel the conversation arrived on.
     */
    public function reply(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->status === ConversationStatus::Closed) {
            return ApiResponse::error('This conversation is closed. Reopen it before replying.', 409);
        }

        $externalId = $request->filled('external_id') ? $request->validated('external_id') : null;

        if ($conversation->channel === 'whatsapp') {
            // The gateway writes the outbound message itself, using the id the
            // provider assigned, so history and provider stay in step.
            $messageId = $this->gateway->sendText($conversation, $request->validated('body'), $externalId);
        } else {
            $message = $this->conversations->addMessage($conversation, MessageDirection::Outbound, [
                'user_id' => $request->user()->getKey(),
                'body' => $request->validated('body'),
                'external_id' => $externalId,
            ]);

            $messageId = $message->external_id;
        }

        return ApiResponse::created('Message sent.', [
            'conversation_id' => $conversation->getKey(),
            'message_id' => $messageId,
        ]);
    }

    public function update(UpdateConversationRequest $request, Conversation $conversation): ConversationResource
    {
        $attributes = $request->validated();

        if (isset($attributes['status'])) {
            $conversation = $this->conversations->changeStatus(
                $conversation,
                ConversationStatus::from($attributes['status']),
            );

            unset($attributes['status']);
        }

        if ($attributes !== []) {
            $conversation = $this->conversations->update($conversation, $attributes);
        }

        return new ConversationResource($conversation);
    }

    public function assign(Request $request, Conversation $conversation): ConversationResource
    {
        $this->conversations->assign($conversation, $request->integer('user_id'));

        return new ConversationResource($conversation->load('assignee'));
    }

    /**
     * Counters for the inbox badge.
     */
    public function summary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'open' => Conversation::query()->withStatus(ConversationStatus::Open)->count(),
                'pending' => Conversation::query()->withStatus(ConversationStatus::Pending)->count(),
                'unassigned' => Conversation::query()->withStatus(ConversationStatus::Open)->whereNull('assigned_to')->count(),
            ],
        ]);
    }

    public function destroy(Conversation $conversation): Response
    {
        $this->conversations->changeStatus($conversation, ConversationStatus::Closed);

        return response()->noContent();
    }
}
