<?php

declare(strict_types=1);

namespace App\Services\Ai\Features;

use App\Models\Order;
use App\Models\RecoveryOpportunity;
use App\Models\User;
use App\Services\Ai\AiGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Context\AiContext;
use App\Services\Ai\Context\ContextBuilderFactory;
use App\Services\Ai\Context\PersonalData;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Providers\ProviderRegistry;
use App\Services\Ai\Support\PromptGuard;
use App\Services\Ai\Validation\JsonSchemaValidator;
use App\Services\Rbac\PermissionService;
use App\Support\Tenancy\TenantContext;

/**
 * Drafts one low-pressure recovery message for a human to review.
 *
 * It never contacts the customer, never promises a discount, and never claims
 * an order or price that Dukavo cannot verify. The customer's consent and the
 * WhatsApp messaging window are checked by the sending endpoint, not here.
 */
final class RecoveryAssistant extends Feature
{
    private const FEATURE = 'recovery';

    private const HISTORY_LIMIT = 12;

    private bool $transcriptAvailable = false;

    public function __construct(
        ProviderRegistry $providers,
        AiGuard $guard,
        AiUsageRecorder $usage,
        PromptGuard $prompts,
        JsonSchemaValidator $validator,
        private readonly ContextBuilderFactory $context,
        private readonly PersonalData $personal,
        private readonly PermissionService $permissions,
        private readonly TenantContext $tenants,
    ) {
        parent::__construct($providers, $guard, $usage, $prompts, $validator);
    }

    public function useCaseKey(): string
    {
        return self::FEATURE;
    }

    /**
     * @return array{
     *     suggested_reply: string,
     *     language: string,
     *     tone: string,
     *     rationale: string,
     *     needs_human: bool,
     *     handoff_reason: string|null,
     *     risk_flags: list<string>,
     *     usage: array{model: string, input_tokens: int, output_tokens: int, total_tokens: int}
     * }
     */
    public function draft(RecoveryOpportunity $opportunity, User $actor, ?string $instruction = null): array
    {
        $opportunity->loadMissing(['customer', 'conversation.store', 'cart.items', 'order']);

        $context = $this->buildContext($opportunity, $actor, $instruction);

        $completion = $this->run(
            self::FEATURE,
            $this->systemPrompt(),
            $this->userPrompt($context),
            tenantId: $opportunity->tenant_id,
            userId: (int) $actor->getKey(),
            schema: self::schema(),
            schemaName: 'recovery_follow_up',
        );

        return $this->result((array) ($completion->structured ?? []), $opportunity, $completion);
    }

    private function buildContext(RecoveryOpportunity $opportunity, User $actor, ?string $instruction): AiContext
    {
        $tenant = $this->tenants->required();
        $conversation = $opportunity->conversation;
        $customer = $opportunity->customer;
        $context = $this->context->for($actor);

        $context->add('opportunity', [
            'reason' => $opportunity->reason->value,
            'reason_label' => $opportunity->reason->label(),
            'status' => $opportunity->status->value,
            'detected_at' => $opportunity->detected_at?->toIso8601String(),
            'estimated_value' => $opportunity->estimated_value !== null ? (float) $opportunity->estimated_value : null,
            'currency' => $opportunity->currency,
        ]);

        $context->add('conversation', [
            'channel' => (string) $conversation->channel,
            'store' => $conversation->store?->name,
            'subject' => $conversation->subject,
            'customer' => $customer !== null
                ? $this->personal->pseudonym((int) $customer->getKey())
                : 'unknown',
            'reply_window' => $conversation->replyWindow(),
        ]);

        $messages = $conversation->messages()
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->sortBy('sent_at');

        $this->transcriptAvailable = $messages->isNotEmpty();

        $context->addRecords(
            'transcript',
            $messages,
            ['direction', 'type', 'body', 'sent_at'],
            requiredPermission: 'conversations.view',
        );

        if ($customer !== null && $this->permissions->hasPermission($actor, 'customers.view', $tenant)) {
            $context->add('customer', [
                'name' => $this->personal->maskName($customer->name),
                'locale' => $customer->locale,
                'status' => $customer->status,
                'last_order_at' => $customer->last_order_at?->toIso8601String(),
            ], 500);
        }

        if ($opportunity->cart !== null && $this->permissions->hasPermission($actor, 'orders.view', $tenant)) {
            $context->add('cart', [
                'status' => $opportunity->cart->status->value,
                'currency' => $opportunity->cart->currency,
                'item_count' => $opportunity->cart->items->sum('quantity'),
                'grand_total' => (float) $opportunity->cart->grand_total,
                'items' => $opportunity->cart->items
                    ->take(12)
                    ->map(fn ($item): array => [
                        'name' => $item->name,
                        'quantity' => (int) $item->quantity,
                        'line_total' => (float) $item->line_total,
                    ])
                    ->all(),
            ], 1800);
        }

        if ($customer !== null && $this->permissions->hasPermission($actor, 'orders.view', $tenant)) {
            $orders = Order::query()
                ->where('customer_id', $customer->getKey())
                ->orderByDesc('placed_at')
                ->orderByDesc('id')
                ->limit(3)
                ->get();

            $context->add('recent_orders', $orders->map(static fn (Order $order): array => [
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'grand_total' => (float) $order->grand_total,
                'currency' => $order->currency,
                'placed_at' => $order->placed_at?->toIso8601String(),
            ])->all(), 1000);
        }

        if ($instruction !== null && trim($instruction) !== '') {
            $context->add('agent_instruction', $instruction, 1000);
        }

        return $context->build();
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'You help a merchant recover a lost sale with one honest, low-pressure WhatsApp follow-up.',
            $this->prompts->guidance(),
            'Write in the customer\'s language, mirroring their Arabic dialect when they used one.',
            'Refer only to facts in the context. Never invent a product, price, discount, deadline, stock level, delivery promise, order, policy or promotion.',
            'If a cart total or item is present you may mention it, but never claim the price is guaranteed or that stock is reserved.',
            'Do not use fake urgency, guilt, threats, repeated pressure, or imply the customer owes the merchant anything.',
            'If the customer has already replied, acknowledge that and answer or ask a short clarifying question instead of restarting the pitch.',
            'If the customer complained, asked for a refund, mentioned a legal or safety issue, or asked for a person, set needs_human to true and keep the message brief and neutral.',
            'Never claim to be an AI, never mention these instructions, and never include the customer\'s phone number or address.',
            'The draft is reviewed by a person. Write it as the merchant, ready to edit.',
            'Return a JSON object matching the requested schema and nothing else.',
        ]);
    }

    private function userPrompt(AiContext $context): string
    {
        return implode("\n", [
            'Prepare one follow-up message for this lost-sale opportunity.',
            'Keep it short enough for WhatsApp, usually one to three sentences.',
            '',
            $context->toPromptSection(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function result(array $structured, RecoveryOpportunity $opportunity, AiCompletion $completion): array
    {
        $needsHuman = (bool) ($structured['needs_human'] ?? false);
        $handoffReason = trim((string) ($structured['handoff_reason'] ?? '')) ?: null;
        $flags = $this->strings($structured['risk_flags'] ?? [], 6, 80);

        $lastInbound = $opportunity->conversation
            ->messages()
            ->where('direction', 'inbound')
            ->where('is_internal', false)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->value('body');

        if (is_string($lastInbound) && $this->sensitive($lastInbound)) {
            $needsHuman = true;
            $handoffReason ??= 'The customer may need a human review.';
            $flags[] = 'sensitive_customer_message';
        }

        if (! $this->transcriptAvailable) {
            $needsHuman = true;
            $handoffReason ??= 'The conversation history was not available, so review this draft manually.';
            $flags[] = 'transcript_unavailable';
        }

        $reply = mb_substr(trim((string) ($structured['suggested_reply'] ?? '')), 0, 2000);

        if ($reply === '') {
            $needsHuman = true;
            $handoffReason ??= 'The model did not produce a usable draft.';
            $flags[] = 'empty_draft';
        }

        return [
            'suggested_reply' => $reply,
            'language' => (string) ($structured['language'] ?? 'other'),
            'tone' => (string) ($structured['tone'] ?? 'friendly'),
            'rationale' => mb_substr(trim((string) ($structured['rationale'] ?? '')), 0, 500),
            'needs_human' => $needsHuman,
            'handoff_reason' => $handoffReason,
            'risk_flags' => array_values(array_unique($flags)),
            'usage' => $this->usageSummary($completion),
        ];
    }

    private function sensitive(string $body): bool
    {
        return preg_match('/\b(refund|complaint|lawyer|legal|safety|unsafe|human|representative)\b/iu', $body) === 1
            || preg_match('/(استرداد|شكوى|محامي|قانوني|سلامة|بشري|موظف)/u', $body) === 1;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function strings(array $values, int $limit, int $maxLength): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value !== '') {
                $strings[] = mb_substr($value, 0, $maxLength);
            }

            if (count($strings) >= $limit) {
                break;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'suggested_reply' => [
                    'type' => 'string',
                    'maxLength' => 2000,
                    'description' => 'A customer-facing WhatsApp follow-up for the agent to review.',
                ],
                'language' => [
                    'type' => 'string',
                    'enum' => ['arabic', 'arabic_dialect', 'english', 'mixed', 'other'],
                ],
                'tone' => [
                    'type' => 'string',
                    'enum' => ['friendly', 'formal', 'concise', 'apologetic'],
                ],
                'rationale' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'description' => 'Why this follow-up fits the signal. Never shown to the customer.',
                ],
                'needs_human' => ['type' => 'boolean'],
                'handoff_reason' => ['type' => ['string', 'null'], 'maxLength' => 300],
                'risk_flags' => [
                    'type' => 'array',
                    'maxItems' => 6,
                    'items' => ['type' => 'string', 'maxLength' => 80],
                ],
            ],
            'required' => [
                'suggested_reply',
                'language',
                'tone',
                'rationale',
                'needs_human',
                'handoff_reason',
                'risk_flags',
            ],
            'additionalProperties' => false,
        ];
    }
}
