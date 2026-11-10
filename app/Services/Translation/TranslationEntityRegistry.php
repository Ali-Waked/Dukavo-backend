<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\FaqEntry;
use App\Models\Newsletter;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Services\Storefront\StorefrontSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * The catalogue of content Dukavo can translate.
 *
 * A definition names the model, the prose fields worth translating and where
 * an approved value is mirrored. Operational data — prices, stock, slugs,
 * coverage, status — is deliberately absent: translating a product must never
 * be able to change what it costs.
 */
final class TranslationEntityRegistry
{
    /**
     * @var array<string, TranslationEntity>
     */
    private array $entities = [];

    public function __construct()
    {
        foreach ($this->definitions() as $entity) {
            $this->entities[$entity->key] = $entity;
        }
    }

    /**
     * @return list<TranslationEntity>
     */
    public function all(): array
    {
        return array_values($this->entities);
    }

    public function get(string $key): ?TranslationEntity
    {
        return $this->entities[$key] ?? null;
    }

    public function forModel(Model $model): ?TranslationEntity
    {
        foreach ($this->entities as $entity) {
            if ($model instanceof $entity->model) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->entities);
    }

    /**
     * @return list<TranslationEntity>
     */
    private function definitions(): array
    {
        return [
            new TranslationEntity(
                key: 'product',
                label: 'Product',
                model: Product::class,
                tenantScoped: true,
                fields: [
                    new TranslationField('name', 'Product name', 'text', 255),
                    new TranslationField('description', 'Description', 'text', 10000),
                ],
                source: static fn (Model $model, string $field): ?string => $model instanceof Product
                    ? $model->getAttribute($field)
                    : null,
                mirror: static function (Model $model, string $field, string $locale, string $text): void {
                    // Products have no legacy translation columns. The review
                    // ledger is the overlay the storefront reads.
                },
                storeId: static fn (Model $model): ?int => $model instanceof Product ? $model->store_id : null,
                displayName: static fn (Model $model): string => $model instanceof Product ? (string) $model->name : '',
                protectedTerms: static fn (Model $model): array => [],
            ),
            new TranslationEntity(
                key: 'category',
                label: 'Category',
                model: Category::class,
                tenantScoped: true,
                fields: [
                    new TranslationField('name', 'Category name', 'text', 255),
                    new TranslationField('description', 'Description', 'text', 10000),
                ],
                source: static fn (Model $model, string $field): ?string => $model instanceof Category
                    ? $model->getAttribute($field)
                    : null,
                mirror: static function (Model $model, string $field, string $locale, string $text): void {
                    if (! $model instanceof Category || $locale !== 'ar') {
                        return;
                    }

                    $column = $field.'_ar';

                    if (in_array($column, ['name_ar', 'description_ar'], true)) {
                        $model->forceFill([$column => $text])->saveQuietly();
                    }
                },
                storeId: static fn (Model $model): ?int => null,
                displayName: static fn (Model $model): string => $model instanceof Category ? (string) $model->name : '',
                protectedTerms: static fn (Model $model): array => [],
            ),
            new TranslationEntity(
                key: 'store',
                label: 'Store',
                model: Store::class,
                tenantScoped: true,
                fields: [
                    new TranslationField('name', 'Store name', 'text', 255),
                    new TranslationField('description', 'Store description', 'text', 5000),
                    new TranslationField('settings.tagline', 'Tagline', 'text', 255),
                    new TranslationField('settings.about', 'About', 'text', 5000),
                    new TranslationField('settings.address', 'Address', 'text', 500),
                    new TranslationField('settings.opening_hours', 'Opening hours', 'text', 500),
                    new TranslationField('settings.delivery_note', 'Delivery note', 'text', 2000),
                    new TranslationField('settings.return_policy', 'Return policy', 'text', 5000),
                    new TranslationField('settings.meta_title', 'Meta title', 'text', 255),
                    new TranslationField('settings.meta_description', 'Meta description', 'text', 500),
                ],
                source: static function (Model $model, string $field): ?string {
                    if (! $model instanceof Store) {
                        return null;
                    }

                    if (! str_starts_with($field, 'settings.')) {
                        return $model->getAttribute($field);
                    }

                    $settings = StorefrontSettings::merge($model->storefront_settings);
                    $value = $settings[substr($field, strlen('settings.'))] ?? null;

                    return is_string($value) ? $value : null;
                },
                mirror: static function (Model $model, string $field, string $locale, string $text): void {
                    if (! $model instanceof Store) {
                        return;
                    }

                    if (! str_starts_with($field, 'settings.')) {
                        if ($locale !== 'ar') {
                            return;
                        }

                        $column = $field.'_ar';

                        if (in_array($column, ['name_ar', 'description_ar'], true)) {
                            $model->forceFill([$column => $text])->saveQuietly();
                        }

                        return;
                    }

                    // The `_ar` sibling is the legacy storefront setting. Other
                    // locales are resolved from the review ledger by the public
                    // resource, so the stored settings document stays honest.
                    if ($locale === 'ar') {
                        $settings = StorefrontSettings::merge($model->storefront_settings);
                        $settings[substr($field, strlen('settings.')).'_ar'] = $text;
                        $model->forceFill(['storefront_settings' => $settings])->saveQuietly();
                    }
                },
                storeId: static fn (Model $model): ?int => $model instanceof Store ? (int) $model->getKey() : null,
                displayName: static fn (Model $model): string => $model instanceof Store ? (string) $model->name : '',
                protectedTerms: static fn (Model $model): array => $model instanceof Store && is_string($model->name) ? [$model->name] : [],
            ),
            new TranslationEntity(
                key: 'shipping_zone',
                label: 'Shipping zone',
                model: ShippingZone::class,
                tenantScoped: true,
                fields: [
                    new TranslationField('name', 'Zone name', 'text', 255),
                    new TranslationField('description', 'Description', 'text', 2000),
                    new TranslationField('method_name', 'Delivery method name', 'text', 255),
                    new TranslationField('method_description', 'Delivery method description', 'text', 2000),
                    new TranslationField('delivery_instructions', 'Delivery instructions', 'text', 2000),
                    new TranslationField('terms', 'Terms', 'text', 5000),
                ],
                source: static fn (Model $model, string $field): ?string => $model instanceof ShippingZone
                    ? $model->getAttribute($field)
                    : null,
                mirror: static function (Model $model, string $field, string $locale, string $text): void {
                    if (! $model instanceof ShippingZone || $locale === ShippingZone::CANONICAL_LOCALE) {
                        return;
                    }

                    if (! in_array($field, ShippingZone::TRANSLATABLE_FIELDS, true)) {
                        return;
                    }

                    $document = is_array($model->translations) ? $model->translations : [];
                    $document[$locale][$field] = $text;

                    $model->forceFill(['translations' => $document])->saveQuietly();
                },
                storeId: static fn (Model $model): ?int => $model instanceof ShippingZone ? $model->store_id : null,
                displayName: static fn (Model $model): string => $model instanceof ShippingZone ? (string) $model->name : '',
                protectedTerms: static fn (Model $model): array => [],
            ),
            new TranslationEntity(
                key: 'faq_entry',
                label: 'FAQ entry',
                model: FaqEntry::class,
                tenantScoped: false,
                fields: [
                    new TranslationField('question', 'Question', 'text', 500),
                    new TranslationField('answer', 'Answer', 'html', 10000),
                ],
                source: static fn (Model $model, string $field): ?string => $model instanceof FaqEntry
                    ? $model->getAttribute($field)
                    : null,
                mirror: static function (Model $model, string $field, string $locale, string $text): void {
                    if (! $model instanceof FaqEntry || $locale !== 'ar') {
                        return;
                    }

                    $column = $field.'_ar';

                    if (in_array($column, ['question_ar', 'answer_ar'], true)) {
                        $model->forceFill([$column => $text])->saveQuietly();
                    }
                },
                storeId: static fn (Model $model): ?int => null,
                displayName: static fn (Model $model): string => $model instanceof FaqEntry ? (string) $model->question : '',
                protectedTerms: static fn (Model $model): array => [],
            ),
            new TranslationEntity(
                key: 'newsletter',
                label: 'Newsletter edition',
                model: Newsletter::class,
                tenantScoped: false,
                fields: [
                    new TranslationField('title', 'Title', 'text', 255),
                    new TranslationField('excerpt', 'Excerpt', 'text', 1000),
                    new TranslationField('body', 'Body', 'html', 50000),
                    new TranslationField('meta_title', 'Meta title', 'text', 255),
                    new TranslationField('meta_description', 'Meta description', 'text', 500),
                ],
                source: static fn (Model $model, string $field): ?string => $model instanceof Newsletter
                    ? $model->getAttribute($field)
                    : null,
                mirror: static function (Model $model, string $field, string $locale, string $text): void {
                    if (! $model instanceof Newsletter || $locale !== 'ar') {
                        return;
                    }

                    $column = $field.'_ar';

                    if (in_array($column, ['title_ar', 'excerpt_ar', 'body_ar', 'meta_title_ar', 'meta_description_ar'], true)) {
                        $model->forceFill([$column => $text])->saveQuietly();
                    }
                },
                storeId: static fn (Model $model): ?int => null,
                displayName: static fn (Model $model): string => $model instanceof Newsletter ? (string) $model->title : '',
                protectedTerms: static fn (Model $model): array => [],
            ),
            new TranslationEntity(
                key: 'content_page',
                label: 'Content page',
                model: ContentPage::class,
                tenantScoped: false,
                fields: [
                    new TranslationField('title', 'Title', 'text', 255),
                    new TranslationField('intro', 'Introduction', 'text', 5000),
                    new TranslationField('meta_title', 'Meta title', 'text', 255),
                    new TranslationField('meta_description', 'Meta description', 'text', 500),
                ],
                source: static fn (Model $model, string $field): ?string => $model instanceof ContentPage
                    ? $model->getAttribute($field)
                    : null,
                mirror: static function (Model $model, string $field, string $locale, string $text): void {
                    // Pages are stored one row per locale. The canonical row is
                    // never overwritten; the review ledger is the overlay.
                },
                storeId: static fn (Model $model): ?int => null,
                displayName: static fn (Model $model): string => $model instanceof ContentPage ? (string) $model->title : '',
                protectedTerms: static fn (Model $model): array => [],
            ),
            new TranslationEntity(
                key: 'automation_rule',
                label: 'Automation rule',
                model: AutomationRule::class,
                tenantScoped: true,
                fields: [
                    new TranslationField('name', 'Rule name', 'text', 255),
                    new TranslationField('description', 'Description', 'text', 5000),
                ],
                source: static fn (Model $model, string $field): ?string => $model instanceof AutomationRule
                    ? $model->getAttribute($field)
                    : null,
                mirror: static function (Model $model, string $field, string $locale, string $text): void {
                    // Automation copy has no legacy language column; the review
                    // ledger is the overlay the merchant UI reads.
                },
                storeId: static fn (Model $model): ?int => $model instanceof AutomationRule ? $model->store_id : null,
                displayName: static fn (Model $model): string => $model instanceof AutomationRule ? (string) $model->name : '',
                protectedTerms: static fn (Model $model): array => [],
            ),
        ];
    }
}
