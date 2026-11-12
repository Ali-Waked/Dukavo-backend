<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\ConfigurationSource;

/**
 * One setting after precedence has been applied.
 *
 * `value` is the effective value the backend should use. For a secret it is
 * the decrypted plaintext and must never leave the process; {@see self::toArray()}
 * is the only shape the HTTP layer is allowed to serialize and it replaces the
 * value with a configured state and an optional last-four hint.
 */
final readonly class ResolvedConfiguration
{
    public function __construct(
        public ConfigurationDefinition $definition,
        public mixed $value,
        public ConfigurationSource $source,
        public bool $configured,
        public ?string $secretHint = null,
    ) {}

    public function key(): string
    {
        return $this->definition->key;
    }

    public function isSecret(): bool
    {
        return $this->definition->isSecret();
    }

    /**
     * The value a client may see. Secrets are always null.
     */
    public function publicValue(): mixed
    {
        return $this->isSecret() ? null : $this->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $definition = $this->definition;

        return [
            'key' => $definition->key,
            'field' => str_starts_with($definition->key, $definition->category->value.'.')
                ? substr($definition->key, strlen($definition->category->value) + 1)
                : $definition->key,
            'category' => $definition->category->value,
            'group' => $definition->group,
            'type' => $definition->type->value,
            'label' => $definition->label,
            'label_ar' => $definition->labelAr,
            'description' => $definition->description,
            'description_ar' => $definition->descriptionAr,
            'value' => $this->publicValue(),
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'configured' => $this->configured,
            'editable' => $definition->editable(),
            'is_secret' => $definition->isSecret(),
            'secret_hint' => $definition->isSecret() ? $this->secretHint : null,
            'requires_restart' => $definition->requiresRestart,
            'options' => $definition->options,
            'min' => $definition->min,
            'max' => $definition->max,
            'max_length' => $definition->maxLength,
            'default' => $definition->default,
            'env_key' => $definition->envKey,
        ];
    }
}
