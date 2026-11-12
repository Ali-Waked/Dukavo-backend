<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\ConfigurationCategory;
use App\Enums\ConfigurationSourcePolicy;
use App\Enums\ConfigurationType;
use Illuminate\Validation\Rule;

/**
 * One typed, described configuration option.
 *
 * A definition is the only way a key can exist. Unknown keys are rejected by
 * the registry before they reach the database, and a definition carries the
 * metadata the dashboard needs: whether it is secret, whether it may be
 * overridden at all, which environment variable it falls back to and whether a
 * running worker must be refreshed after a change.
 */
final readonly class ConfigurationDefinition
{
    /**
     * @param  list<string>  $options  Allowed values for a select.
     * @param  list<string>  $rules  Extra Laravel validation rules.
     */
    public function __construct(
        public string $key,
        public ConfigurationCategory $category,
        public ConfigurationType $type,
        public string $label,
        public string $labelAr,
        public string $description,
        public string $descriptionAr,
        public mixed $default = null,
        public ?string $envKey = null,
        public ?string $configPath = null,
        public bool $requiresRestart = false,
        public ConfigurationSourcePolicy $sourcePolicy = ConfigurationSourcePolicy::DatabasePreferred,
        public array $options = [],
        public ?int $min = null,
        public ?int $max = null,
        public ?int $maxLength = null,
        public array $rules = [],
        public ?string $group = null,
    ) {}

    public function isSecret(): bool
    {
        return $this->type === ConfigurationType::Secret;
    }

    public function editable(): bool
    {
        return $this->sourcePolicy->editable();
    }

    /**
     * Laravel validation rules for a value supplied to this definition.
     *
     * The caller adds `sometimes`/`nullable`; this method states what the value
     * itself must be, so both the partial section update and a full import use
     * the same contract.
     *
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        $rules = match ($this->type) {
            ConfigurationType::String, ConfigurationType::Text => ['string'],
            ConfigurationType::Boolean => ['boolean'],
            ConfigurationType::Integer => ['integer'],
            ConfigurationType::Decimal => ['numeric'],
            ConfigurationType::Email => ['string', 'email', 'max:255'],
            ConfigurationType::Url => ['string', 'url', 'max:2048'],
            ConfigurationType::Secret => ['string', 'max:4096'],
            ConfigurationType::Select => ['string', Rule::in($this->options)],
            ConfigurationType::MultiSelect => ['array'],
        };

        if ($this->maxLength !== null && in_array($this->type, [
            ConfigurationType::String,
            ConfigurationType::Text,
            ConfigurationType::Secret,
        ], true)) {
            $rules[] = 'max:'.$this->maxLength;
        }

        if ($this->min !== null && in_array($this->type, [
            ConfigurationType::Integer,
            ConfigurationType::Decimal,
        ], true)) {
            $rules[] = 'min:'.$this->min;
        }

        if ($this->max !== null && in_array($this->type, [
            ConfigurationType::Integer,
            ConfigurationType::Decimal,
        ], true)) {
            $rules[] = 'max:'.$this->max;
        }

        if ($this->type === ConfigurationType::MultiSelect) {
            $rules[] = 'min:1';
            $options = $this->options;
            $rules[] = static function (string $attribute, mixed $value, \Closure $fail) use ($options): void {
                foreach ((array) $value as $item) {
                    if (! is_string($item) || ! in_array($item, $options, true)) {
                        $fail('The selected '.$attribute.' contains an unsupported value.');

                        return;
                    }
                }
            };
        }

        return array_values(array_merge($rules, $this->rules));
    }

    /**
     * Cast a stored/request value into its application form.
     */
    public function cast(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            ConfigurationType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            ConfigurationType::Integer => (int) $value,
            ConfigurationType::Decimal => (float) $value,
            ConfigurationType::MultiSelect => $this->castList($value),
            default => (string) $value,
        };
    }

    /**
     * The form a value takes inside the `platform_settings.value` column.
     */
    public function serialize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            ConfigurationType::Boolean => $value ? '1' : '0',
            ConfigurationType::MultiSelect => json_encode(array_values((array) $value), JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }

    /**
     * Whether a resolved value counts as configured for health purposes.
     */
    public function isConfigured(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value)) {
            return true;
        }

        return trim((string) $value) !== '';
    }

    /**
     * @return list<string>
     */
    private function castList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_string'));
            }

            return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
