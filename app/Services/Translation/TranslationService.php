<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Enums\TranslationOrigin;
use App\Enums\TranslationStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AiUsage;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Translation;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Features\TranslationAssistant;
use App\Services\Storefront\StorefrontSettings;
use App\Support\HtmlSanitizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

/**
 * The translation review workflow.
 *
 * Generate, edit, approve, reject and stale-marking live here so every entry
 * point — controller, observer, future queue job — follows the same rules:
 * AI output is a proposal, a human approves it, a manual edit is never
 * regenerated without explicit confirmation, and a stale row keeps the last
 * approved copy visible while asking for a fresh review.
 */
final class TranslationService
{
    public function __construct(
        private readonly TranslationEntityRegistry $registry,
        private readonly LanguageDetector $detector,
        private readonly TranslationOutputValidator $validator,
        private readonly TranslationAssistant $assistant,
        private readonly HtmlSanitizer $sanitizer,
        private readonly TenantContext $tenants,
    ) {}

    public function registry(): TranslationEntityRegistry
    {
        return $this->registry;
    }

    /**
     * Resolve an entity by its registry key, enforcing workspace isolation.
     *
     * @return array{entity: TranslationEntity, model: Model}
     */
    public function resolve(string $key, int $id, ?Tenant $tenant): array
    {
        $entity = $this->registry->get($key);

        if ($entity === null) {
            throw (new ModelNotFoundException)->setModel($key, [$id]);
        }

        // Platform content is only managed from the platform surface, and
        // workspace content only from a resolved workspace. A mismatched route
        // is a 404, never a way to read across the boundary.
        if ($entity->tenantScoped !== ($tenant !== null)) {
            throw (new ModelNotFoundException)->setModel($entity->model, [$id]);
        }

        $query = $entity->model::query();

        if ($entity->tenantScoped && $tenant !== null) {
            $query->where('tenant_id', $tenant->getKey());
        }

        $model = $query->find($id);

        if (! $model instanceof Model) {
            throw (new ModelNotFoundException)->setModel($entity->model, [$id]);
        }

        return ['entity' => $entity, 'model' => $model];
    }

    /**
     * The target languages for an entity: the store's enabled list, never a
     * hardcoded pair.
     *
     * @return list<string>
     */
    public function enabledLocales(TranslationEntity $entity, Model $model): array
    {
        $supported = array_map(
            static fn (mixed $code): string => strtolower((string) $code),
            (array) config('dukavo.locales.supported', ['en']),
        );

        $store = $this->storeFor($entity, $model);
        $enabled = $store?->enabled_locales;

        if (! is_array($enabled) || $enabled === []) {
            $enabled = (array) config('dukavo.locales.enabled_default', ['en', 'ar']);
        }

        $enabled = array_values(array_unique(array_filter(
            array_map(static fn (mixed $code): string => strtolower(trim((string) $code)), $enabled),
            static fn (string $code): bool => in_array($code, $supported, true),
        )));

        if ($enabled === []) {
            $enabled = [(string) config('dukavo.locales.default', 'en')];
        }

        return $enabled;
    }

    /**
     * The review screen's payload for one record.
     *
     * @param  list<string>|null  $fields
     * @return array<string, mixed>
     */
    public function overview(TranslationEntity $entity, Model $model, ?string $sourceLocale, ?Tenant $tenant, ?array $fields = null): array
    {
        $sources = $entity->sourceFields($model, $fields === null ? [] : array_combine($fields, $fields));
        $enabled = $this->enabledLocales($entity, $model);
        $detection = $this->detectSource($sources, $enabled);
        $source = $this->explicitSource($sourceLocale) ?? ($this->detector->isConfident($detection) ? $detection['locale'] : null);

        $targets = $source === null
            ? $enabled
            : array_values(array_filter($enabled, static fn (string $locale): bool => $locale !== $source));

        $rows = $this->rowMap($model, $tenant);

        $translations = [];
        $counts = array_fill_keys(array_map(static fn (TranslationStatus $status): string => $status->value, TranslationStatus::cases()), 0);

        foreach ($targets as $locale) {
            foreach (array_keys($sources) as $field) {
                $row = $rows[$locale.'|'.$field] ?? null;

                if ($row === null) {
                    $counts[TranslationStatus::NotTranslated->value]++;

                    $translations[$locale][$field] = [
                        'id' => null,
                        'locale' => $locale,
                        'field' => $field,
                        'status' => TranslationStatus::NotTranslated->value,
                        'status_label' => TranslationStatus::NotTranslated->label(),
                        'translated_text' => null,
                        'published_text' => null,
                        'manually_edited' => false,
                        'origin' => null,
                        'source_locale' => $source,
                        'updated_at' => null,
                    ];

                    continue;
                }

                $counts[$row->status->value] = ($counts[$row->status->value] ?? 0) + 1;
                $translations[$locale][$field] = $this->present($row);
            }
        }

        return [
            'entity' => [
                'key' => $entity->key,
                'label' => $entity->label,
                'id' => (int) $model->getKey(),
                'name' => $entity->nameFor($model),
                'tenant_scoped' => $entity->tenantScoped,
            ],
            'source_locale' => $source,
            'source_detection' => $detection,
            'source_required' => $source === null,
            'enabled_locales' => $enabled,
            'target_locales' => $targets,
            'fields' => array_map(
                static fn (TranslationField $field): array => [
                    'key' => $field->key,
                    'label' => $field->label,
                    'format' => $field->format,
                ],
                array_values(array_filter($entity->fields, static fn (TranslationField $field): bool => array_key_exists($field->key, $sources))),
            ),
            'sources' => $sources,
            'translations' => $translations,
            'summary' => $counts,
        ];
    }

    /**
     * Generate one or more target locales. Each target is a separate provider
     * call so a failure in one language cannot discard another's draft.
     *
     * @param  list<string>  $fields
     * @param  list<string>  $targetLocales
     * @param  list<string>  $protectedTerms
     * @return array<string, mixed>
     */
    public function generate(
        TranslationEntity $entity,
        Model $model,
        array $fields,
        array $targetLocales,
        ?string $sourceLocale,
        ?int $userId,
        bool $confirmOverwrite = false,
        array $protectedTerms = [],
    ): array {
        $sources = $entity->sourceFields($model, array_combine($fields, $fields));

        if ($sources === []) {
            throw BusinessRuleViolation::because('There is no source text to translate for the selected fields.');
        }

        $enabled = $this->enabledLocales($entity, $model);
        $detection = $this->detectSource($sources, $enabled);
        $source = $this->explicitSource($sourceLocale)
            ?? ($this->detector->isConfident($detection) ? $detection['locale'] : null);

        if ($source === null) {
            throw BusinessRuleViolation::because(
                'Choose the source language so Dukavo translates from the right language.',
                ['detection' => $detection],
                'translation_source_required',
            );
        }

        $formats = [];

        foreach (array_keys($sources) as $field) {
            $formats[$field] = $entity->field((string) $field)?->format ?? 'text';
        }

        $targets = array_values(array_unique(array_map(
            static fn (mixed $locale): string => strtolower(trim((string) $locale)),
            $targetLocales === [] ? $enabled : $targetLocales,
        )));

        $targets = array_values(array_filter(
            $targets,
            static fn (string $locale): bool => $locale !== '' && $locale !== $source && in_array($locale, $enabled, true),
        ));

        if ($targets === []) {
            throw BusinessRuleViolation::because(
                'Choose at least one enabled target language other than the source language.'
            );
        }

        $this->assertOverwriteAllowed($entity, $model, $sources, $targets, $confirmOverwrite);

        $tenant = $this->tenants->tenant();
        $terms = array_values(array_unique(array_merge($entity->protectedTermsFor($model), $protectedTerms)));
        $results = [];

        foreach ($targets as $target) {
            $lock = Cache::lock(
                'translation:'.$model->getMorphClass().':'.$model->getKey().':'.$target,
                120,
            );

            if (! $lock->get()) {
                throw BusinessRuleViolation::because(
                    'A translation for this language is already being generated.',
                    ['locale' => $target],
                    'translation_in_progress',
                );
            }

            try {
                $results[$target] = $this->generateForLocale(
                    $entity,
                    $model,
                    $sources,
                    $formats,
                    $source,
                    $target,
                    $terms,
                    $tenant,
                    $userId,
                );
            } finally {
                $lock->release();
            }
        }

        return [
            'source_locale' => $source,
            'source_detection' => $detection,
            'results' => $results,
            'translations' => $this->rowMapPayload($model, $tenant),
        ];
    }

    /**
     * Generate one target locale. The caller holds the per-record/per-locale
     * lock, so two taps on "Generate" cannot bill the merchant twice.
     *
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $formats
     * @param  list<string>  $terms
     * @return array{locale: string, status: string, fields: list<string>, usage: array<string, int|string>}
     */
    private function generateForLocale(
        TranslationEntity $entity,
        Model $model,
        array $sources,
        array $formats,
        string $source,
        string $target,
        array $terms,
        ?Tenant $tenant,
        ?int $userId,
    ): array {
        $rows = $this->markGenerating($entity, $model, $sources, $source, $target, $tenant);

        try {
            $answer = $this->assistant->translate(
                $sources,
                $formats,
                $source,
                $target,
                $tenant?->getKey(),
                (int) $userId,
                $terms,
                ['entity' => $entity->label, 'name' => $entity->nameFor($model)],
            );
        } catch (\Throwable $exception) {
            $this->markFailed($rows, 'provider_failed', ['message' => $exception->getMessage()]);

            throw $exception;
        }

        $translated = [];

        foreach (array_keys($sources) as $field) {
            $value = $answer['translations'][$field] ?? null;
            $translated[$field] = is_string($value) ? trim($value) : '';
        }

        $errors = $this->validator->validateMany($sources, $translated, $formats, $terms);

        if ($errors !== []) {
            $this->markFailed($rows, 'translation_validation_failed', ['fields' => $errors]);

            throw AiRequestFailed::invalidOutput('the translation did not preserve required content: '.json_encode($errors));
        }

        foreach (array_keys($sources) as $field) {
            $text = $translated[$field];

            if (($formats[$field] ?? 'text') === 'html') {
                $text = $this->sanitizer->clean($text);
            }

            if (trim($text) === '') {
                $this->markFailed($rows, 'translation_empty', ['field' => $field]);

                throw AiRequestFailed::invalidOutput('a translated field was empty after sanitisation');
            }

            $row = $rows[$field];
            $row->forceFill([
                'translated_text' => $text,
                'ai_text' => $text,
                'status' => TranslationStatus::Generated,
                'origin' => TranslationOrigin::Ai,
                'manually_edited' => false,
                'source_locale' => $source,
                'source_text' => $sources[$field],
                'source_hash' => $this->hash($sources[$field]),
                'provider' => $answer['provider'] !== '' ? $answer['provider'] : null,
                'model' => $answer['model'] !== '' ? $answer['model'] : null,
                'generated_at' => now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
                'rejection_reason' => null,
                'metadata' => null,
            ])->save();
        }

        return [
            'locale' => $target,
            'status' => TranslationStatus::Generated->value,
            'fields' => array_keys($sources),
            'usage' => $answer['usage'],
        ];
    }

    public function update(Translation $translation, string $text, ?int $userId): Translation
    {
        $text = trim($text);

        if ($text === '') {
            throw BusinessRuleViolation::because('A translation cannot be empty. Reject it instead.', [], 'translation_empty');
        }

        $translation->forceFill([
            'translated_text' => $text,
            'origin' => TranslationOrigin::Manual,
            'manually_edited' => true,
            'status' => TranslationStatus::NeedsReview,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'rejection_reason' => null,
        ])->save();

        $this->forgetEntityCache($translation);

        return $translation->refresh();
    }

    public function approve(Translation $translation, int $userId, ?Tenant $tenant = null): Translation
    {
        if (! $translation->status->hasCopy() || $translation->translated_text === null) {
            throw BusinessRuleViolation::because('This translation has no copy to approve.', [], 'translation_empty');
        }

        if ($translation->status === TranslationStatus::Stale
            || $this->sourceChanged($translation)) {
            if ($translation->status !== TranslationStatus::Stale) {
                $translation->forceFill(['status' => TranslationStatus::Stale])->save();
            }

            throw BusinessRuleViolation::because(
                'The source text changed. Regenerate this translation before approving it.',
                ['translation_id' => $translation->getKey()],
                'translation_stale',
            );
        }

        $entity = $this->registry->get((string) $translation->translatable_type);
        $model = $entity === null ? null : $this->modelFor($translation, $entity, $tenant);

        $translation->forceFill([
            'status' => TranslationStatus::Approved,
            'published_text' => $translation->translated_text,
            'reviewed_at' => now(),
            'reviewed_by' => $userId,
            'rejection_reason' => null,
        ])->save();

        if ($entity !== null && $model instanceof Model) {
            $entity->mirror($model, (string) $translation->field, (string) $translation->locale, (string) $translation->translated_text);
            $this->forgetEntityCache($translation);
        }

        return $translation->refresh();
    }

    public function reject(Translation $translation, ?string $reason, int $userId): Translation
    {
        $translation->forceFill([
            'status' => TranslationStatus::Rejected,
            'rejection_reason' => $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 255),
            'reviewed_at' => now(),
            'reviewed_by' => $userId,
        ])->save();

        return $translation->refresh();
    }

    public function regenerate(Translation $translation, bool $confirmOverwrite, ?int $userId): array
    {
        $entity = $this->registry->get((string) $translation->translatable_type);

        if ($entity === null) {
            throw (new ModelNotFoundException)->setModel(Translation::class, [$translation->getKey()]);
        }

        $model = $this->modelFor($translation, $entity, $this->tenants->tenant());

        if (! $model instanceof Model) {
            throw (new ModelNotFoundException)->setModel($entity->model, [(int) $translation->translatable_id]);
        }

        return $this->generate(
            $entity,
            $model,
            [(string) $translation->field],
            [(string) $translation->locale],
            (string) $translation->source_locale,
            $userId,
            $confirmOverwrite,
        );
    }

    /**
     * Approve exactly the selected rows. The id set is verified against the
     * record, so a caller cannot approve a translation shown on another screen.
     *
     * @param  list<int>  $ids
     */
    public function approveMany(array $ids, TranslationEntity $entity, Model $model, int $userId, ?Tenant $tenant): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            throw BusinessRuleViolation::because('Select at least one translation to approve.', [], 'translation_selection_empty');
        }

        $rows = Translation::query()
            ->forRecord($model)
            ->whereIn('id', $ids)
            ->when($tenant === null, fn (Builder $query) => $query->whereNull('tenant_id'))
            ->when($tenant !== null, fn (Builder $query) => $query->where('tenant_id', $tenant->getKey()))
            ->get();

        if ($rows->count() !== count($ids)) {
            throw BusinessRuleViolation::because(
                'One or more selected translations do not belong to this record.',
                ['requested' => count($ids), 'found' => $rows->count()],
                'translation_selection_mismatch',
            );
        }

        $approved = [];

        foreach ($rows as $row) {
            $approved[] = $this->approve($row, $userId, $tenant);
        }

        return $approved;
    }

    /**
     * Mark rows stale when their source changed. The copy is left untouched;
     * the last approved text stays published.
     *
     * @param  list<string>  $fields
     */
    public function markStale(Model $model, array $fields): int
    {
        $fields = array_values(array_unique(array_filter($fields, static fn (mixed $field): bool => is_string($field) && $field !== '')));

        if ($fields === []) {
            return 0;
        }

        return Translation::query()
            ->forRecord($model)
            ->whereIn('field', $fields)
            ->whereIn('status', [
                TranslationStatus::Generated->value,
                TranslationStatus::NeedsReview->value,
                TranslationStatus::Approved->value,
                TranslationStatus::Stale->value,
            ])
            ->update(['status' => TranslationStatus::Stale->value, 'updated_at' => now()]);
    }

    /**
     * Translate a model change into the registry field keys that changed.
     *
     * @param  list<string>  $dirty
     */
    public function markDirtySourcesStale(Model $model, array $dirty): void
    {
        $entity = $this->registry->forModel($model);

        if ($entity === null) {
            return;
        }

        $fields = [];

        foreach ($entity->fields as $field) {
            if (str_starts_with($field->key, 'settings.')) {
                continue;
            }

            if (in_array($field->key, $dirty, true)) {
                $fields[] = $field->key;
            }
        }

        if (in_array('storefront_settings', $dirty, true)) {
            $original = StorefrontSettings::merge($model->getOriginal('storefront_settings') ?: []);
            $current = StorefrontSettings::merge(is_array($model->getAttribute('storefront_settings')) ? $model->getAttribute('storefront_settings') : []);

            foreach ($entity->fields as $field) {
                if (! str_starts_with($field->key, 'settings.')) {
                    continue;
                }

                $key = substr($field->key, strlen('settings.'));

                if (($original[$key] ?? null) !== ($current[$key] ?? null)) {
                    $fields[] = $field->key;
                }
            }
        }

        if ($fields !== []) {
            $this->markStale($model, $fields);
        }
    }

    /**
     * The translation usage recorded today, for the review screen's cost note.
     *
     * @return array{requests: int, input_tokens: int, output_tokens: int, total_tokens: int, estimated_cost: float}
     */
    public function usageToday(?Tenant $tenant, ?int $userId = null): array
    {
        $query = AiUsage::query()
            ->where('feature', 'translation')
            ->where('created_at', '>=', now()->startOfDay())
            ->when($tenant !== null, fn (Builder $query) => $query->where('tenant_id', $tenant->getKey()))
            ->when($tenant === null, fn (Builder $query) => $query->whereNull('tenant_id'))
            ->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId));

        return [
            'requests' => (int) (clone $query)->count(),
            'input_tokens' => (int) (clone $query)->sum('input_tokens'),
            'output_tokens' => (int) (clone $query)->sum('output_tokens'),
            'total_tokens' => (int) (clone $query)->sum('total_tokens'),
            'estimated_cost' => round((float) (clone $query)->sum('estimated_cost'), 6),
        ];
    }

    /**
     * @param  array<string, string>  $sources
     * @param  list<string>  $enabled
     * @return array{locale: string|null, confidence: float, method: string}
     */
    private function detectSource(array $sources, array $enabled): array
    {
        return $this->detector->detect(
            array_values($sources),
            array_values(array_unique([...$enabled, ...array_map('strval', (array) config('dukavo.locales.supported', ['en']))])),
        );
    }

    private function explicitSource(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $locale = strtolower(trim($locale));

        return in_array($locale, array_map('strval', (array) config('dukavo.locales.supported', ['en'])), true)
            ? $locale
            : null;
    }

    private function storeFor(TranslationEntity $entity, Model $model): ?Store
    {
        if ($model instanceof Store) {
            return $model;
        }

        $storeId = $entity->storeIdFor($model);

        return $storeId === null ? null : Store::query()->find($storeId);
    }

    /**
     * @param  list<string>  $targets
     */
    private function assertOverwriteAllowed(TranslationEntity $entity, Model $model, array $sources, array $targets, bool $confirm): void
    {
        if ($confirm) {
            return;
        }

        $locked = Translation::query()
            ->forRecord($model)
            ->whereIn('locale', $targets)
            ->whereIn('field', array_keys($sources))
            ->where(function (Builder $query): void {
                $query->where('manually_edited', true)
                    ->orWhere('status', TranslationStatus::Approved->value);
            })
            ->get(['locale', 'field']);

        if ($locked->isNotEmpty()) {
            throw BusinessRuleViolation::because(
                'Some translations were edited by a person or already approved. Confirm before regenerating them.',
                ['locked' => $locked->map(static fn (Translation $row): string => $row->locale.':'.$row->field)->values()->all()],
                'translation_confirmation_required',
            );
        }
    }

    /**
     * @param  array<string, string>  $sources
     * @return array<string, Translation>
     */
    private function markGenerating(TranslationEntity $entity, Model $model, array $sources, string $sourceLocale, string $target, ?Tenant $tenant): array
    {
        $rows = [];

        foreach ($sources as $field => $source) {
            $row = Translation::query()->firstOrNew([
                'translatable_type' => $model->getMorphClass(),
                'translatable_id' => $model->getKey(),
                'locale' => $target,
                'field' => $field,
            ]);

            $row->fill([
                'tenant_id' => $tenant?->getKey(),
                'store_id' => $entity->storeIdFor($model),
                'source_locale' => $sourceLocale,
                'source_text' => $source,
                'source_hash' => $this->hash($source),
                'status' => TranslationStatus::Generating,
                'origin' => TranslationOrigin::Ai,
                'rejection_reason' => null,
                'metadata' => null,
            ])->save();

            $rows[$field] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, Translation>  $rows
     * @param  array<string, mixed>  $metadata
     */
    private function markFailed(array $rows, string $reason, array $metadata = []): void
    {
        foreach ($rows as $row) {
            $row->forceFill([
                'status' => TranslationStatus::Failed,
                'metadata' => ['reason' => $reason] + $metadata,
            ])->save();
        }
    }

    private function sourceChanged(Translation $translation): bool
    {
        $entity = $this->registry->get((string) $translation->translatable_type);

        if ($entity === null) {
            return true;
        }

        $model = $this->modelFor($translation, $entity, $this->tenants->tenant());

        if (! $model instanceof Model) {
            return true;
        }

        $source = $entity->sourceText($model, (string) $translation->field);

        if ($source === null) {
            return true;
        }

        return $this->hash($source) !== (string) $translation->source_hash;
    }

    private function modelFor(Translation $translation, TranslationEntity $entity, ?Tenant $tenant): ?Model
    {
        $query = $entity->model::query();

        if ($entity->tenantScoped && $tenant !== null) {
            $query->where('tenant_id', $tenant->getKey());
        }

        return $query->find((int) $translation->translatable_id);
    }

    /**
     * @return array<string, Translation>
     */
    private function rowMap(Model $model, ?Tenant $tenant): array
    {
        return Translation::query()
            ->forRecord($model)
            ->when($tenant === null, fn (Builder $query) => $query->whereNull('tenant_id'))
            ->when($tenant !== null, fn (Builder $query) => $query->where('tenant_id', $tenant->getKey()))
            ->get()
            ->keyBy(static fn (Translation $row): string => $row->locale.'|'.$row->field)
            ->all();
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function rowMapPayload(Model $model, ?Tenant $tenant): array
    {
        $payload = [];

        foreach ($this->rowMap($model, $tenant) as $row) {
            $payload[$row->locale][$row->field] = $this->present($row);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Translation $row): array
    {
        return [
            'id' => (int) $row->getKey(),
            'locale' => $row->locale,
            'field' => $row->field,
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'translated_text' => $row->translated_text,
            'published_text' => $row->published_text,
            'manually_edited' => $row->manually_edited,
            'origin' => $row->origin->value,
            'source_locale' => $row->source_locale,
            'provider' => $row->provider,
            'model' => $row->model,
            'generated_at' => $row->generated_at?->toIso8601String(),
            'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            'reviewed_by' => $row->reviewed_by,
            'rejection_reason' => $row->rejection_reason,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }

    private function hash(string $value): string
    {
        return hash('sha256', trim($value));
    }

    private function forgetEntityCache(Translation $translation): void
    {
        $entity = $this->registry->get((string) $translation->translatable_type);

        if ($entity === null) {
            return;
        }

        $model = $this->modelFor($translation, $entity, $this->tenants->tenant());

        if ($model instanceof Model && method_exists($model, 'forgetTranslationCache')) {
            $model->forgetTranslationCache();
        }
    }
}
