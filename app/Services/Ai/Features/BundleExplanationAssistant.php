<?php

declare(strict_types=1);

namespace App\Services\Ai\Features;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Context\ContextBuilderFactory;
use App\Services\Ai\Providers\ProviderRegistry;
use App\Services\Ai\Support\PromptGuard;
use App\Services\Ai\Validation\JsonSchemaValidator;

/**
 * Writes one short, grounded reason a product belongs in a bundle with another.
 *
 * The model receives only the two products and the evidence the recommendation
 * service recomputed on the server. It is explicitly forbidden from turning an
 * order count into a conversion rate or a promised lift, and from inventing
 * demand that is not in the evidence block.
 */
final class BundleExplanationAssistant extends Feature
{
    private const FEATURE = 'bundle_explanation';

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
     * @param  array<string, mixed>  $evidence
     * @return array{explanation: string, usage: array{model: string, input_tokens: int, output_tokens: int, total_tokens: int}}
     */
    public function explain(Product $anchor, Product $partner, array $evidence, User $actor): array
    {
        $context = $this->context->for($actor);
        $context->add('bundle_candidate', [
            'anchor' => [
                'name' => $anchor->name,
                'category' => $anchor->category?->name,
                'price' => (float) $anchor->price,
                'currency' => $anchor->currency,
            ],
            'partner' => [
                'name' => $partner->name,
                'category' => $partner->category?->name,
                'price' => (float) $partner->price,
                'currency' => $partner->currency,
            ],
            'evidence' => [
                'basis' => $evidence['basis'] ?? null,
                'confidence' => $evidence['confidence'] ?? null,
                'order_count' => $evidence['order_count'] ?? null,
                'unit_count' => $evidence['unit_count'] ?? null,
                'window_days' => $evidence['window_days'] ?? null,
                'last_observed_at' => $evidence['last_observed_at'] ?? null,
            ],
        ]);

        $completion = $this->run(
            self::FEATURE,
            $this->systemPrompt(),
            $context->build()->toPromptSection(),
            tenantId: (int) $anchor->tenant_id,
            userId: (int) $actor->getKey(),
            schema: self::schema(),
            schemaName: 'bundle_explanation',
        );

        return [
            'explanation' => trim((string) (($completion->structured ?? [])['explanation'] ?? '')),
            'usage' => $this->usageSummary($completion),
        ];
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'You write one concise, factual sentence for a merchant explaining why two products make a sensible bundle.',
            $this->prompts->guidance(),
            'Use only the verified evidence block. Never invent an order, customer, product, amount, date or statistic.',
            'Never describe an order count as a conversion rate, conversion lift, probability or predicted revenue.',
            'When the evidence basis is same_category, say plainly that it is a catalogue match, not measured demand.',
            'When the evidence basis is purchased_together, cite the exact order count and window from the block.',
            'Do not mention customer names or personal data; none are provided.',
            'Keep the explanation to at most two short sentences and write in English.',
            'Return a JSON object matching the requested schema and nothing else.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'explanation' => [
                    'type' => 'string',
                    'maxLength' => 600,
                    'description' => 'One or two grounded sentences for staff.',
                ],
            ],
            'required' => ['explanation'],
            'additionalProperties' => false,
        ];
    }
}
