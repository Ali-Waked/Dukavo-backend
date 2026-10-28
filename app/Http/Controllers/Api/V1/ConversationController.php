<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\AssignConversationRequest;
use App\Http\Requests\Conversations\IndexConversationsRequest;
use App\Http\Requests\Conversations\SendMessageRequest;
use App\Http\Requests\Conversations\SendNoteRequest;
use App\Http\Requests\Conversations\SendTemplateRequest;
use App\Http\Requests\Conversations\StartConversationRequest;
use App\Http\Requests\Conversations\UpdateConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Conversations\ConversationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function index(IndexConversationsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $search = trim((string) ($filters['search'] ?? ''));

        $conversations = Conversation::query()
            ->with(['customer', 'assignee', 'latestMessage'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->withStatus($status))
            ->when($filters['channel'] ?? null, fn ($query, string $channel) => $query->where('channel', $channel))
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->where('store_id', (int) $storeId))
            ->when($request->boolean('mine'), fn ($query) => $query->where('assigned_to', $request->user()->getKey()))
            ->when($request->boolean('unassigned'), fn ($query) => $query->whereNull('assigned_to'))
            ->when($request->boolean('unread'), fn ($query) => $query->where('unread_count', '>', 0))
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('subject', 'like', $term)
                        ->orWhere('display_name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('channel_account', 'like', $term)
                        ->orWhereHas('customer', fn ($customer) => $customer
                            ->where('name', 'like', $term)
                            ->orWhere('phone', 'like', $term))
                        ->orWhereHas('messages', fn ($message) => $message->where('body', 'like', $term));
                });
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return ConversationResource::collection($conversations);
    }

    public function show(Conversation $conversation): ConversationResource
    {
        $conversation->load(['customer.addresses', 'assignee', 'store', 'latestMessage']);

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
            $this->conversations->assertTextReplyAllowed($conversation);

            $this->conversations->addMessage(
                $conversation,
                MessageDirection::from($request->validated('direction', MessageDirection::Outbound->value)),
                [
                    'user_id' => $request->user()->getKey(),
                    'body' => $request->validated('body'),
                    'idempotency_key' => $request->validated('external_id'),
                ],
            );
        }

        return ApiResponse::created('Conversation started.', new ConversationResource($conversation->refresh()->load('latestMessage')));
    }

    /**
     * The message history of one thread, newest first for backwards paging.
     */
    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        return MessageResource::collection(
            $conversation->messages()
                ->with(['author', 'voiceMessage'])
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

        $this->conversations->assertTextReplyAllowed($conversation);

        $idempotencyKey = $request->validated('idempotency_key')
            ?? $request->validated('external_id')
            ?? (string) Str::uuid();

        if ($conversation->channel === 'whatsapp') {
            // The gateway writes the outbound message itself, using the id the
            // provider assigned, so history and provider stay in step.
            $providerId = $this->gateway->sendText($conversation, $request->validated('body'), $idempotencyKey);
            $message = $this->messageByProviderId($conversation, $providerId);
        } else {
            $message = $this->conversations->addMessage($conversation, MessageDirection::Outbound, [
                'user_id' => $request->user()->getKey(),
                'type' => MessageType::Text,
                'body' => $request->validated('body'),
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        return ApiResponse::created('Message sent.', new MessageResource($message->load('author')));
    }

    /**
     * Add a private note. Notes are Dukavo-only records: they are never passed to
     * a provider and are visibly marked as internal in the thread.
     */
    public function note(SendNoteRequest $request, Conversation $conversation): JsonResponse
    {
        $message = $this->conversations->addMessage($conversation, MessageDirection::Outbound, [
            'user_id' => $request->user()->getKey(),
            'type' => MessageType::Note,
            'body' => $request->validated('body'),
            'is_internal' => true,
            'delivery_status' => null,
        ]);

        return ApiResponse::created('Note added.', new MessageResource($message->load('author')));
    }

    /**
     * Send an approved template, which is the only way to reopen a WhatsApp
     * conversation after the customer's 24-hour window has closed.
     */
    public function template(SendTemplateRequest $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->status === ConversationStatus::Closed) {
            return ApiResponse::error('This conversation is closed. Reopen it before replying.', 409);
        }

        if ($conversation->channel !== 'whatsapp') {
            return ApiResponse::error('Templates are only available for WhatsApp conversations.', 422);
        }

        $idempotencyKey = $request->validated('idempotency_key') ?? (string) Str::uuid();

        $providerId = $this->gateway->sendTemplate(
            $conversation,
            $request->validated('template_name'),
            $request->validated('language_code'),
            $request->validated('parameters', []),
            $idempotencyKey,
        );

        return ApiResponse::created(
            'Template sent.',
            new MessageResource($this->messageByProviderId($conversation, $providerId)->load('author')),
        );
    }

    /**
     * Retry an outbound text that the provider marked failed. A retry is a new
     * provider message linked to the original, never an edit of the old row.
     */
    public function retry(Conversation $conversation, ConversationMessage $message): JsonResponse
    {
        abort_unless($message->conversation_id === $conversation->getKey(), 404);

        if (! $message->canRetry()) {
            return ApiResponse::error('This message cannot be retried.', 409);
        }

        if ($conversation->channel !== 'whatsapp') {
            return ApiResponse::error('Retries are only available for WhatsApp messages.', 422);
        }

        if ($conversation->status === ConversationStatus::Closed) {
            return ApiResponse::error('This conversation is closed. Reopen it before replying.', 409);
        }

        $this->conversations->assertTextReplyAllowed($conversation);

        $idempotencyKey = 'retry:'.$message->getKey();
        $providerId = $this->gateway->sendText($conversation, (string) $message->body, $idempotencyKey);
        $retry = $this->messageByProviderId($conversation, $providerId);

        $retry->forceFill([
            'retry_of_id' => $message->getKey(),
            'attempt_count' => (int) $message->attempt_count + 1,
        ])->save();

        return ApiResponse::created('Message retried.', new MessageResource($retry->load('author')));
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

        return new ConversationResource($conversation->load(['customer', 'assignee', 'latestMessage']));
    }

    public function assign(AssignConversationRequest $request, Conversation $conversation): ConversationResource
    {
        $this->conversations->assign($conversation, $request->validated('user_id'));

        return new ConversationResource($conversation->load(['assignee', 'latestMessage']));
    }

    /**
     * Team members the current inbox user may assign. This is intentionally
     * narrower than team management and does not require `users.view`.
     */
    public function assignees(): AnonymousResourceCollection
    {
        return UserResource::collection($this->conversations->assignableUsers());
    }

    public function markRead(Conversation $conversation): ConversationResource
    {
        $this->conversations->markRead($conversation);

        return new ConversationResource($conversation->load(['customer', 'assignee', 'latestMessage']));
    }

    /**
     * Stream a stored inbound attachment through an authenticated route. Disk
     * paths, provider media ids and tokens never reach the browser.
     */
    public function media(Conversation $conversation, ConversationMessage $message): StreamedResponse
    {
        abort_unless($message->conversation_id === $conversation->getKey(), 404);

        $path = data_get($message->meta, 'media.path');

        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            (string) (data_get($message->meta, 'media.filename') ?? 'attachment'),
            [
                'Content-Type' => (string) (data_get($message->meta, 'media.mime_type') ?? 'application/octet-stream'),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=300',
            ],
            'inline',
        );
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
                'unread' => (int) Conversation::query()->sum('unread_count'),
            ],
        ]);
    }

    public function destroy(Conversation $conversation): Response
    {
        $this->conversations->changeStatus($conversation, ConversationStatus::Closed);

        return response()->noContent();
    }

    private function messageByProviderId(Conversation $conversation, string $providerId): ConversationMessage
    {
        $message = $conversation->messages()
            ->where('external_id', $providerId)
            ->where('direction', MessageDirection::Outbound)
            ->latest('id')
            ->first();

        if (! $message instanceof ConversationMessage) {
            // An accepted provider message that cannot be found locally is a
            // consistency error, never a reason to pretend the send failed.
            report(new \RuntimeException('Outbound WhatsApp message was not persisted after provider acceptance.'));

            abort(500, 'The message was accepted by the provider but could not be recorded locally.');
        }

        return $message;
    }
}
