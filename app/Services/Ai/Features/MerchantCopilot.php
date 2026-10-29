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
use App\Services\Inventory\InventoryIntelligenceService;
use App\Services\Reporting\DashboardService;
use App\Services\Reporting\ProfitIntelligenceService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

final class MerchantCopilot extends Feature
{
    private const FEATURE = 'merchant_copilot';

    public function __construct(
        ProviderRegistry $providers,
        AiGuard $guard,
        AiUsageRecorder $usage,
        PromptGuard $prompts,
        JsonSchemaValidator $validator,
        private readonly DashboardService $dashboard,
        private readonly ProfitIntelligenceService $profit,
        private readonly InventoryIntelligenceService $inventory,
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
    public function answer(string $question, ?int $storeId, string $from, string $until, int $userId): array
    {
        $tenant = $this->tenants->required();
        $store = $storeId !== null
            ? Store::query()->where('tenant_id', $tenant->getKey())->findOrFail($storeId)
            : null;

        $fromDate = CarbonImmutable::parse($from)->startOfDay();
        $untilDate = CarbonImmutable::parse($until)->endOfDay();

        $facts = $this->facts($store, $tenant->currency, $fromDate, $untilDate);

        $completion = $this->run(
            self::FEATURE,
            $this->systemPrompt(),
            $this->userPrompt($question, $facts),
            tenantId: (int) $tenant->getKey(),
            userId: $userId,
            schema: self::schema(),
            schemaName: 'merchant_copilot',
        );

        return $this->shape((array) ($completion->structured ?? []), $facts, $completion);
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(?Store $store, string $currency, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $summary = $this->dashboard->summary($store);
        $revenue = $this->dashboard->revenueSeries(min((int) $from->diffInDays($until) + 1, 365), $store);
        $top = $this->dashboard->topProducts(5, $store);
        $dead = $this->dashboard->deadStock(5);

        $profit = $this->profit->overview($store, strtoupper($currency), $from, $until, 5);
        $inventory = $this->inventory->overview($store, $from, $until, 'all', null, 1, 20);

        return [
            'scope' => [
                'store_id' => $store?->getKey(),
                'store_name' => $store?->name,
                'currency' => strtoupper($currency),
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
                'timezone' => $profit['range']['timezone'] ?? config('app.timezone'),
            ],
            'dashboard' => [
                'summary' => $summary,
                'revenue_series' => $revenue,
                'top_products' => $top,
                'dead_stock' => $dead,
            ],
            'profit' => [
                'summary' => $profit['summary'],
                'limitations' => $profit['limitations'],
                'series' => array_slice($profit['series'], -14),
            ],
            'inventory' => [
                'summary' => $inventory['summary'],
                'items' => array_slice(array_map(static function (array $item): array {
                    return [
                        'product_name' => $item['product_name'],
                        'sku' => $item['sku'],
                        'status' => $item['status'],
                        'available' => $item['available'],
                        'forecast' => [
                            'status' => $item['forecast']['status'],
                            'daily_demand' => $item['forecast']['daily_demand'],
                            'confidence' => $item['forecast']['confidence'],
                        ],
                        'replenishment' => [
                            'needed' => $item['replenishment']['needed'],
                            'suggested_quantity' => $item['replenishment']['suggested_quantity'],
                            'days_of_cover' => $item['replenishment']['days_of_cover'],
                        ],
                    ];
                }, $inventory['items']), 0, 15),
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'You are a Merchant Copilot for a WhatsApp commerce merchant.',
            $this->prompts->guidance(),
            'You only use the provided factual data. Never invent KPIs, sales, customers, costs, or inventory figures.',
            'Every numeric/financial value in your answer must come from the provided facts. Do not compute new totals.',
            'State the period and scope. Distinguish facts from estimates/recommendations. If data is missing/insufficient, say so explicitly.',
            'Suggest actions only as previews/links (e.g. review low stock, draft follow-up, review bundle, open report). Never execute actions or generate SQL.',
            'Return JSON matching the schema only.',
        ]);
    }

    private function userPrompt(string $question, array $facts): string
    {
        $json = json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return implode("\n", [
            'Question:',
            $question,
            '',
            'Authorized facts (tenant/store/date scoped):',
            $this->prompts->wrap('facts', $json, 16000),
        ]);
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function shape(array $structured, array $facts, AiCompletion $completion): array
    {
        $scope = $facts['scope'] ?? [];

        return [
            'answer' => trim((string) ($structured['answer'] ?? '')),
            'period' => [
                'from' => $scope['from'] ?? null,
                'until' => $scope['until'] ?? null,
                'timezone' => $scope['timezone'] ?? null,
            ],
            'scope' => [
                'store_id' => $scope['store_id'] ?? null,
                'store_name' => $scope['store_name'] ?? null,
                'currency' => $scope['currency'] ?? null,
            ],
            'facts_used' => array_values((array) ($structured['facts_used'] ?? [])),
            'missing_or_insufficient' => array_values((array) ($structured['missing_or_insufficient'] ?? [])),
            'estimates_and_recommendations' => array_values((array) ($structured['estimates_and_recommendations'] ?? [])),
            'metric_explanations' => array_values((array) ($structured['metric_explanations'] ?? [])),
            'action_previews' => array_values((array) ($structured['action_previews'] ?? [])),
            'data_gaps' => array_values((array) ($structured['data_gaps'] ?? [])),
            'usage' => $this->usageSummary($completion),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $action = [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string', 'maxLength' => 80],
                'type' => ['type' => 'string', 'enum' => ['review_low_stock', 'draft_follow_up', 'review_bundle', 'open_report', 'other']],
                'reason' => ['type' => 'string', 'maxLength' => 200],
                'link_hint' => ['type' => 'string', 'maxLength' => 120],
            ],
            'required' => ['label', 'type', 'reason', 'link_hint'],
            'additionalProperties' => false,
        ];

        $explanation = [
            'type' => 'object',
            'properties' => [
                'metric' => ['type' => 'string', 'maxLength' => 80],
                'definition' => ['type' => 'string', 'maxLength' => 200],
                'source' => ['type' => 'string', 'maxLength' => 80],
            ],
            'required' => ['metric', 'definition', 'source'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string', 'maxLength' => 2000],
                'facts_used' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
                'missing_or_insufficient' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
                'estimates_and_recommendations' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
                'metric_explanations' => ['type' => 'array', 'items' => $explanation, 'maxItems' => 10],
                'action_previews' => ['type' => 'array', 'items' => $action, 'maxItems' => 6],
                'data_gaps' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
            ],
            'required' => ['answer', 'facts_used', 'missing_or_insufficient', 'estimates_and_recommendations', 'metric_explanations', 'action_previews', 'data_gaps'],
            'additionalProperties' => false,
        ];
    }
}
