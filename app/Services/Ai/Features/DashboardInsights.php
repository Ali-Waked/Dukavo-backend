<?php

declare(strict_types=1);

namespace App\Services\Ai\Features;

use App\Models\Store;
use App\Services\Ai\AiGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Providers\ProviderRegistry;
use App\Services\Ai\Support\PromptGuard;
use App\Services\Ai\Validation\JsonSchemaValidator;
use App\Services\Reporting\DashboardService;
use App\Services\Reporting\PlatformDashboardService;
use App\Support\Tenancy\TenantContext;

/**
 * Turns the dashboard's already-computed aggregates into a short, readable
 * briefing with recommendations.
 *
 * The model is given nothing but the numbers {@see DashboardService} and
 * {@see PlatformDashboardService} produced from real tables. It is explicitly
 * forbidden from inventing figures, and any empty or zero metric must be called
 * out as "no data" rather than glossed over — so the briefing can be trusted to
 * describe Dukavo's actual state, never a plausible-sounding fiction.
 */
final class DashboardInsights extends Feature
{
    private const FEATURE = 'insights';

    public function __construct(
        ProviderRegistry $providers,
        AiGuard $guard,
        AiUsageRecorder $usage,
        PromptGuard $prompts,
        JsonSchemaValidator $validator,
        private readonly DashboardService $dashboard,
        private readonly PlatformDashboardService $platform,
        private readonly TenantContext $tenants,
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
    public function forTenant(?Store $store, int $userId): array
    {
        $metrics = [
            'context' => $this->dashboard->context(),
            'summary' => $this->dashboard->summary($store),
            'top_products' => $this->dashboard->topProducts(5, $store),
            'dead_stock' => $this->dashboard->deadStock(5),
        ];

        $completion = $this->run(
            self::FEATURE,
            $this->systemPrompt(),
            $this->userPrompt($metrics),
            tenantId: $this->tenants->id(),
            userId: $userId,
            schema: self::schema(),
            schemaName: 'tenant_insights',
        );

        return $this->result($completion);
    }

    /**
     * @return array<string, mixed>
     */
    public function forPlatform(string $range, int $userId): array
    {
        $overview = $this->platform->overview($range);

        // The time-series rows are dropped: they are the largest part of the
        // payload and add nothing the totals and status meters do not already
        // summarise, so excluding them keeps the prompt small and cheap.
        unset($overview['series']);

        $completion = $this->run(
            self::FEATURE,
            $this->systemPrompt(),
            $this->userPrompt($overview),
            tenantId: null,
            userId: $userId,
            schema: self::schema(),
            schemaName: 'platform_insights',
        );

        return $this->result($completion);
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'You are a careful business analyst for Dukavo, a WhatsApp commerce platform.',
            $this->prompts->guidance(),
            'Write a short, plain-language briefing for the numbers provided in the data block.',
            'Use ONLY the figures in that block. Never invent, estimate or extrapolate a number, trend, product, customer or date.',
            'When a metric is zero or absent, say that there is no data for it instead of implying activity.',
            'Keep the summary to three sentences at most; keep every highlight and recommendation to one sentence.',
            'Recommendations must be concrete and follow from the provided numbers.',
            'Do not add figures together unless the block already contains their sum.',
            'Return a JSON object matching the requested schema and nothing else.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function userPrompt(array $metrics): string
    {
        $json = json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return "Metrics provided by Dukavo (source of truth):\n".$this->prompts->wrap('metrics', $json, 12000);
    }

    /**
     * @return array{summary: string, highlights: list<array<string, mixed>>, recommendations: list<array<string, mixed>>, data_gaps: list<string>, usage: array{model: string, input_tokens: int, output_tokens: int, total_tokens: int}}
     */
    private function result(AiCompletion $completion): array
    {
        $structured = $completion->structured ?? [];

        return [
            'summary' => (string) ($structured['summary'] ?? ''),
            'highlights' => array_values((array) ($structured['highlights'] ?? [])),
            'recommendations' => array_values((array) ($structured['recommendations'] ?? [])),
            'data_gaps' => array_values(array_filter(
                array_map(static fn (mixed $value): string => (string) $value, (array) ($structured['data_gaps'] ?? [])),
                static fn (string $value): bool => $value !== '',
            )),
            'usage' => $this->usageSummary($completion),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $recommendation = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'detail' => ['type' => 'string'],
            ],
            'required' => ['title', 'detail'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'highlights' => [
                    'type' => 'array',
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'severity' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'warning']],
                        ],
                        'required' => ['title', 'detail', 'severity'],
                        'additionalProperties' => false,
                    ],
                ],
                'recommendations' => [
                    'type' => 'array',
                    'maxItems' => 4,
                    'items' => $recommendation,
                ],
                'data_gaps' => [
                    'type' => 'array',
                    'maxItems' => 5,
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['summary', 'highlights', 'recommendations', 'data_gaps'],
            'additionalProperties' => false,
        ];
    }
}
