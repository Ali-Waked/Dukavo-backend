<?php

declare(strict_types=1);

namespace App\Services\Ai\Features;

use App\Enums\ProductStatus;
use App\Models\Conversation;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Store;
use App\Models\VoiceMessage;
use App\Services\Ai\AiGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Context\AiContext;
use App\Services\Ai\Context\ContextBuilderFactory;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Providers\ProviderRegistry;
use App\Services\Ai\Support\PromptGuard;
use App\Services\Ai\Validation\JsonSchemaValidator;

/**
 * Turns a transcript into a reviewable order draft grounded in the catalogue.
 *
 * The model only proposes catalogue ids and quantities. Every id, variant,
 * price and stock figure is resolved again here. An ambiguous variant is left
 * unresolved rather than guessed, and nothing is written to a cart or order.
 */
final class VoiceOrderExtractor extends Feature
{
    private const FEATURE = 'voice_order';

    private const CATALOG_LIMIT = 40;

    /** @var array<int, array<string, mixed>> Variant id => verified catalogue facts. */
    private array $variants = [];

    /** @var array<int, array<string, mixed>> Product id => list of variant ids. */
    private array $productVariants = [];

    private bool $catalogueIncluded = false;

    private ?string $currency = null;

    public function __construct(
        ProviderRegistry $providers,
        AiGuard $guard,
        AiUsageRecorder $usage,
        PromptGuard $prompts,
        JsonSchemaValidator $validator,
        private readonly ContextBuilderFactory $context,
    ) {
        parent::__construct($providers, $guard, $usage, $prompts, $validator);
    }

    public function useCaseKey(): string
    {
        return self::FEATURE;
    }

    /**
     * @return array<string, mixed>
     */
    public function extract(VoiceMessage $voice, Conversation $conversation): array
    {
        $conversation->loadMissing(['store']);
        $context = $this->buildContext($voice, $conversation);

        $completion = $this->run(
            self::FEATURE,
            $this->systemPrompt(),
            $this->userPrompt($context->toPromptSection()),
            tenantId: (int) $voice->tenant_id,
            userId: null,
            schema: self::schema(),
            schemaName: 'voice_order_draft',
        );

        return $this->result(
            (array) ($completion->structured ?? []),
            $completion,
            (string) ($voice->transcript ?? ''),
        );
    }

    private function buildContext(VoiceMessage $voice, Conversation $conversation): AiContext
    {
        $context = $this->context->forSystem();

        $context->add('voice_note', [
            'transcript' => $this->prompts->truncate((string) ($voice->transcript ?? ''), 6000),
            'detected_language' => $voice->language,
            'duration_seconds' => null,
        ], 7000);

        $store = $conversation->store
            ?? Store::query()->where('status', 'active')->orderByDesc('is_default')->orderBy('id')->first();

        $this->currency = $store?->currency;

        if ($store === null) {
            return $context->build();
        }

        $products = Product::query()
            ->with('variants')
            ->where('status', ProductStatus::Active)
            ->where(function ($query) use ($store): void {
                $query->whereNull('store_id')->orWhere('store_id', $store->getKey());
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::CATALOG_LIMIT)
            ->get();

        if ($products->isEmpty()) {
            return $context->build();
        }

        $variantIds = $products->flatMap(static fn (Product $product) => $product->variants->pluck('id'))->all();

        $stock = Inventory::query()
            ->where('store_id', $store->getKey())
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->keyBy('product_variant_id');

        $rows = [];

        foreach ($products as $product) {
            $variants = [];

            foreach ($product->variants as $variant) {
                $available = $stock->get($variant->getKey())?->available() ?? 0;

                $this->productVariants[(int) $product->getKey()][] = (int) $variant->getKey();
                $this->variants[(int) $variant->getKey()] = [
                    'product_id' => (int) $product->getKey(),
                    'variant_id' => (int) $variant->getKey(),
                    'name' => $product->name.($variant->is_default ? '' : ' — '.$variant->name),
                    'sku' => $variant->sku ?? $product->sku,
                    'unit_price' => (float) $variant->price,
                    'currency' => (string) ($product->currency ?: $this->currency),
                    'available' => $available,
                ];

                $variants[] = [
                    'variant_id' => (int) $variant->getKey(),
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => (float) $variant->price,
                    'available' => $available,
                    'is_default' => (bool) $variant->is_default,
                ];
            }

            $rows[] = [
                'product_id' => (int) $product->getKey(),
                'name' => $product->name,
                'sku' => $product->sku,
                'description' => $this->prompts->truncate((string) $product->description, 160),
                'variants' => $variants,
            ];
        }

        $context->add('catalogue', $rows, 7000);
        $this->catalogueIncluded = true;

        return $context->build();
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'You turn an Arabic-first customer voice transcript into a draft order for a human agent to review.',
            $this->prompts->guidance(),
            'Use only the products and variants present in the catalogue context. Never invent a product, variant, SKU, price, discount, stock level or promotion.',
            'Copy product_id and variant_id exactly from the catalogue. If a product has more than one variant and the customer did not clearly name one, set variant_id to null and add a clarification instead of guessing.',
            'If the customer did not state a quantity, set quantity to null and add a clarification. Never silently choose a missing product, variant or quantity.',
            'Do not state that an order was placed or confirmed. The result is only a draft for review.',
            'Keep spoken_name as the customer’s own wording for the item. Put size, colour, delivery or substitution requests in notes.',
            'Set intent to order only when the customer is asking to buy. Use product_inquiry for availability or price questions and other for anything else.',
            'Return a JSON object matching the requested schema and nothing else.',
        ]);
    }

    private function userPrompt(string $context): string
    {
        return implode("\n", [
            'Extract the requested order lines from this voice note.',
            'Prices and stock in the catalogue are the only facts you may rely on.',
            '',
            $context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function result(array $structured, AiCompletion $completion, string $transcript): array
    {
        $items = [];
        $unresolved = [];
        $warnings = [];
        $warningCodes = [];

        foreach ((array) ($structured['items'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $resolved = $this->resolve($candidate);

            if ($resolved['resolved']) {
                $variantId = (int) $resolved['variant_id'];
                $items[$variantId] = $resolved;
            } else {
                $unresolved[] = $resolved;
            }
        }

        if (! $this->catalogueIncluded) {
            $warnings[] = 'The catalogue was not available, so no product or stock facts were checked.';
            $warningCodes[] = 'catalogue_unavailable';
        }

        if ($unresolved !== []) {
            $warnings[] = 'Some items need a product, variant or quantity before they can be added.';
            $warningCodes[] = 'unresolved_items';
        }

        if ($items === []) {
            $warnings[] = 'No complete order line was found in this recording.';
            $warningCodes[] = 'no_order_items';
        }

        foreach ($items as $item) {
            if ($item['sufficient_stock'] === false) {
                $warnings[] = sprintf(
                    'Only %d units of %s are available; the request asks for %d.',
                    (int) $item['available'],
                    (string) $item['name'],
                    (int) $item['quantity'],
                );
                $warningCodes[] = 'insufficient_stock';
            }
        }

        $confidence = $structured['confidence'] ?? null;
        $confidence = is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : null;

        if ($confidence !== null && $confidence < 0.6) {
            $warnings[] = 'The recording was unclear. Check the transcript and every line before creating a cart.';
            $warningCodes[] = 'low_confidence';
        }

        return [
            'intent' => (string) ($structured['intent'] ?? 'other'),
            'summary' => mb_substr(trim((string) ($structured['summary'] ?? '')), 0, 600),
            'detected_language' => (string) ($structured['detected_language'] ?? 'other'),
            'confidence' => $confidence,
            'currency' => $this->currency,
            'items' => array_values($items),
            'unresolved' => $unresolved,
            'missing_information' => $this->strings($structured['missing_information'] ?? [], 8, 160),
            'warnings' => array_values(array_unique($warnings)),
            'warning_codes' => array_values(array_unique($warningCodes)),
            'transcript' => $transcript,
            'usage' => $this->usageSummary($completion),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function resolve(array $candidate): array
    {
        $spokenName = mb_substr(trim((string) ($candidate['spoken_name'] ?? '')), 0, 200);
        $productId = $this->positiveInt($candidate['product_id'] ?? null);
        $variantId = $this->positiveInt($candidate['variant_id'] ?? null);
        $quantity = $this->quantity($candidate['quantity'] ?? null);

        $base = [
            'requested_name' => $spokenName,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity' => $quantity,
            'notes' => mb_substr(trim((string) ($candidate['notes'] ?? '')), 0, 300),
        ];

        if ($quantity === null) {
            return $base + [
                'resolved' => false,
                'reason' => 'quantity_required',
                'name' => null,
                'sku' => null,
                'unit_price' => null,
                'currency' => $this->currency,
                'available' => null,
                'sufficient_stock' => null,
                'line_total' => null,
            ];
        }

        if ($variantId !== null) {
            $facts = $this->variants[$variantId] ?? null;

            if ($facts === null || ($productId !== null && $facts['product_id'] !== $productId)) {
                return $this->unresolved($base, 'variant_not_found');
            }

            return $this->line($base, $variantId, $quantity);
        }

        if ($productId === null) {
            return $this->unresolved($base, $spokenName === '' ? 'product_required' : 'product_not_found');
        }

        $variants = $this->productVariants[$productId] ?? [];

        if ($variants === []) {
            return $this->unresolved($base, 'product_not_found');
        }

        if (count($variants) > 1) {
            return $this->unresolved($base, 'variant_required');
        }

        return $this->line($base, (int) $variants[0], $quantity);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function line(array $base, int $variantId, int $quantity): array
    {
        $facts = $this->variants[$variantId] ?? null;

        if ($facts === null) {
            return $this->unresolved($base, 'variant_not_found');
        }

        $lineTotal = round((float) $facts['unit_price'] * $quantity, 2);

        return $base + $facts + [
            'resolved' => true,
            'reason' => null,
            'quantity' => $quantity,
            'line_total' => $lineTotal,
            'sufficient_stock' => (int) $facts['available'] >= $quantity,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function unresolved(array $base, string $reason): array
    {
        return $base + [
            'resolved' => false,
            'reason' => $reason,
            'name' => null,
            'sku' => null,
            'unit_price' => null,
            'currency' => $this->currency,
            'available' => null,
            'sufficient_stock' => null,
            'line_total' => null,
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function quantity(mixed $value): ?int
    {
        $quantity = $this->positiveInt($value);

        return $quantity !== null && $quantity <= 1000 ? $quantity : null;
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
                'intent' => [
                    'type' => 'string',
                    'enum' => ['order', 'product_inquiry', 'other'],
                ],
                'summary' => ['type' => 'string', 'maxLength' => 600],
                'detected_language' => [
                    'type' => 'string',
                    'enum' => ['arabic', 'arabic_dialect', 'english', 'mixed', 'other'],
                ],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'items' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'spoken_name' => ['type' => 'string', 'maxLength' => 200],
                            'product_id' => ['type' => ['integer', 'null']],
                            'variant_id' => ['type' => ['integer', 'null']],
                            'quantity' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 1000],
                            'notes' => ['type' => ['string', 'null'], 'maxLength' => 300],
                        ],
                        'required' => ['spoken_name', 'product_id', 'variant_id', 'quantity', 'notes'],
                        'additionalProperties' => false,
                    ],
                ],
                'missing_information' => [
                    'type' => 'array',
                    'maxItems' => 8,
                    'items' => ['type' => 'string', 'maxLength' => 160],
                ],
            ],
            'required' => ['intent', 'summary', 'detected_language', 'confidence', 'items', 'missing_information'],
            'additionalProperties' => false,
        ];
    }
}
