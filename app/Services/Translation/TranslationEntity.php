<?php

declare(strict_types=1);

namespace App\Services\Translation;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * What the translation layer knows about one translatable entity type.
 *
 * The closures keep entity-specific storage knowledge (a legacy `_ar` column,
 * a JSON document, a nested storefront setting) out of the generic service.
 * Adding a translatable entity means adding one definition here, not another
 * branch in the controller.
 */
final readonly class TranslationEntity
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<TranslationField>  $fields
     * @param  Closure(Model, string): ?string  $source  Returns the canonical source text for a field key.
     * @param  Closure(Model, string, string, string): void  $mirror  Writes an approved value into legacy storage.
     * @param  Closure(Model): ?int  $storeId  The owning store, when the entity is store-scoped.
     * @param  Closure(Model): string  $displayName  A short human label for the record.
     * @param  Closure(Model): list<string>  $protectedTerms  Brand names and other terms that must not be translated.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $model,
        public bool $tenantScoped,
        public array $fields,
        public Closure $source,
        public Closure $mirror,
        public Closure $storeId,
        public Closure $displayName,
        public Closure $protectedTerms,
    ) {}

    /**
     * @return array<string, TranslationField>
     */
    public function fieldMap(): array
    {
        $map = [];

        foreach ($this->fields as $field) {
            $map[$field->key] = $field;
        }

        return $map;
    }

    public function field(string $key): ?TranslationField
    {
        return $this->fieldMap()[$key] ?? null;
    }

    public function sourceText(Model $model, string $field): ?string
    {
        if ($this->field($field) === null) {
            return null;
        }

        $text = ($this->source)($model, $field);

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        return $text;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    public function sourceFields(Model $model, array $values = []): array
    {
        $fields = $values === [] ? array_keys($this->fieldMap()) : array_keys($values);
        $sources = [];

        foreach ($fields as $field) {
            $text = $this->sourceText($model, (string) $field);

            if ($text !== null) {
                $sources[(string) $field] = $text;
            }
        }

        return $sources;
    }

    public function storeIdFor(Model $model): ?int
    {
        $id = ($this->storeId)($model);

        return $id === null ? null : (int) $id;
    }

    public function nameFor(Model $model): string
    {
        $name = trim(($this->displayName)($model));

        return $name === '' ? $this->label.' #'.$model->getKey() : $name;
    }

    /**
     * @return list<string>
     */
    public function protectedTermsFor(Model $model): array
    {
        return array_values(array_filter(
            array_map('trim', ($this->protectedTerms)($model)),
            static fn (string $term): bool => $term !== '',
        ));
    }

    public function mirror(Model $model, string $field, string $locale, string $text): void
    {
        ($this->mirror)($model, $field, $locale, $text);
    }
}
