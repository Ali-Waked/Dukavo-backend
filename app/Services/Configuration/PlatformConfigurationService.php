<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\AuditAction;
use App\Enums\ConfigurationCategory;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The write side of platform configuration.
 *
 * Every mutation follows the same path: reject anything the registry does not
 * allow, store a secret as ciphertext (never plaintext), audit the change with
 * the value redacted, flush the resolver and re-apply the runtime config. The
 * dashboard and any future console command therefore cannot bypass validation
 * or the audit trail by writing the model directly.
 */
final class PlatformConfigurationService
{
    public function __construct(
        private readonly ConfigurationRegistry $registry,
        private readonly ConfigurationResolver $resolver,
        private readonly ConfigurationApplier $applier,
        private readonly SecretEncryptionService $secrets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forCategory(ConfigurationCategory $category): array
    {
        $resolved = $this->resolver->all();

        return array_values(array_map(
            static fn (ConfigurationDefinition $definition): array => $resolved[$definition->key]->toArray(),
            $this->registry->forCategory($category),
        ));
    }

    /**
     * @return list<array{category: string, label: string, label_ar: string, holds_secrets: bool, settings: list<array<string, mixed>>}>
     */
    public function catalogue(): array
    {
        $resolved = $this->resolver->all();

        return array_values(array_map(function (ConfigurationCategory $category) use ($resolved): array {
            $settings = array_map(
                static fn (ConfigurationDefinition $definition): array => $resolved[$definition->key]->toArray(),
                $this->registry->forCategory($category),
            );

            return [
                'category' => $category->value,
                'label' => $category->label(),
                'label_ar' => $category->labelAr(),
                'holds_secrets' => $category->holdsSecrets(),
                'settings' => array_values($settings),
            ];
        }, $this->registry->categories()));
    }

    /**
     * Save a partial section. Keys absent from `$values` are left untouched;
     * an explicit null clears the override and returns to the environment or
     * application default.
     *
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    public function updateCategory(
        ConfigurationCategory $category,
        array $values,
        ?User $actor = null,
        bool $mayManageSecrets = false,
    ): array {
        $normalised = [];

        foreach ($values as $field => $value) {
            $key = is_string($field) && str_contains($field, '.')
                ? $field
                : $category->value.'.'.$field;

            if (! $this->registry->has($key)) {
                throw new InvalidArgumentException(sprintf('Unknown platform configuration key [%s].', $field));
            }

            if ($this->registry->get($key)->category !== $category) {
                throw new InvalidArgumentException(sprintf('Configuration key [%s] does not belong to [%s].', $key, $category->value));
            }

            $normalised[$key] = $value;
        }

        DB::transaction(function () use ($normalised, $actor, $mayManageSecrets): void {
            foreach ($normalised as $key => $value) {
                $this->write($key, $value, $actor, $mayManageSecrets);
            }
        });

        $this->refreshRuntime();

        return $this->forCategory($category);
    }

    /**
     * Write one key. Used by the feature-flag service as well as the settings
     * page, so the same validation and audit apply to both surfaces.
     */
    public function put(string $key, mixed $value, ?User $actor = null, bool $mayManageSecrets = true): ResolvedConfiguration
    {
        DB::transaction(fn () => $this->write($key, $value, $actor, $mayManageSecrets));

        $this->refreshRuntime();

        return $this->resolver->get($key);
    }

    public function forget(string $key, ?User $actor = null): void
    {
        DB::transaction(fn () => $this->write($key, null, $actor, true));

        $this->refreshRuntime();
    }

    /**
     * The audit rows that belong to platform configuration, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 50, ?string $key = null): array
    {
        $query = AuditLog::query()
            ->with('user:id,name,email')
            ->where('auditable_type', (new PlatformSetting)->getMorphClass())
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($key !== null) {
            $query->where(function ($query) use ($key): void {
                $query->where('new_values->key', $key)
                    ->orWhere('old_values->key', $key);
            });
        }

        return $query->limit(max(1, min($limit, 200)))
            ->get()
            ->map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action->value,
                'action_label' => $log->action->label(),
                'key' => $log->new_values['key'] ?? $log->old_values['key'] ?? null,
                'category' => $log->new_values['category'] ?? $log->old_values['category'] ?? null,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'actor' => $log->user === null ? null : [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ],
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * A secret is never written through this method as plaintext into audit or
     * into the response. `$value === null` clears; an empty string means "leave
     * the stored secret alone", which is the only safe reading of a blank
     * password field in a form the operator reopened.
     */
    private function write(string $key, mixed $value, ?User $actor, bool $mayManageSecrets): void
    {
        $definition = $this->registry->get($key);

        if (! $definition->editable()) {
            throw new InvalidArgumentException(sprintf('Configuration key [%s] is managed by the deployment environment and cannot be changed here.', $key));
        }

        if ($definition->isSecret() && ! $mayManageSecrets) {
            throw new AuthorizationException('You are not allowed to change secret platform settings.');
        }

        $existing = PlatformSetting::query()->where('key', $key)->first();
        $oldResolved = $this->resolver->get($key);

        if ($definition->isSecret() && is_string($value) && trim($value) === '') {
            return;
        }

        if (! $definition->isSecret() && is_string($value) && trim($value) === '') {
            $value = null;
        }

        $serialized = $definition->serialize($definition->cast($value));

        if ($serialized === null) {
            if ($existing === null) {
                return;
            }

            $existing->delete();
            $this->auditChange($definition, $existing, $oldResolved, null, $actor);

            return;
        }

        $isSecret = $definition->isSecret();
        $storedValue = $isSecret ? $this->secrets->encrypt((string) $value) : (string) $serialized;
        $hint = $isSecret ? $this->secrets->hint((string) $value) : null;

        if ($existing !== null && $this->isSameValue($definition, $existing, $value)) {
            return;
        }

        $setting = PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'category' => $definition->category->value,
                'value' => $storedValue,
                'is_secret' => $isSecret,
                'secret_hint' => $hint,
                'updated_by' => $actor?->getKey(),
            ],
        );

        $this->auditChange($definition, $setting, $oldResolved, $value, $actor);
    }

    private function isSameValue(ConfigurationDefinition $definition, PlatformSetting $existing, mixed $value): bool
    {
        if ($definition->isSecret()) {
            $current = $this->secrets->decrypt($existing->value);

            return $current !== null && $current === (string) $value;
        }

        return $existing->value === (string) $definition->serialize($definition->cast($value));
    }

    private function auditChange(
        ConfigurationDefinition $definition,
        PlatformSetting $setting,
        ResolvedConfiguration $old,
        mixed $newValue,
        ?User $actor,
    ): void {
        $oldPayload = $this->auditValue($definition, $old->value, $old->configured, $old->secretHint);
        $newPayload = $newValue === null
            ? null
            : $this->auditValue($definition, $definition->cast($newValue), true, $definition->isSecret() ? $this->secrets->hint((string) $newValue) : null);

        $this->audit->record(
            AuditAction::Updated,
            $setting,
            [
                'old' => ['key' => $definition->key, 'category' => $definition->category->value] + $oldPayload,
                'new' => ['key' => $definition->key, 'category' => $definition->category->value] + ($newPayload ?? ['value' => null, 'configured' => false]),
            ],
            $actor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValue(ConfigurationDefinition $definition, mixed $value, bool $configured, ?string $hint): array
    {
        if ($definition->isSecret()) {
            return [
                'value' => null,
                'configured' => $configured,
                'secret_hint' => $hint,
            ];
        }

        return [
            'value' => $definition->cast($value),
            'configured' => $configured,
        ];
    }

    private function refreshRuntime(): void
    {
        $this->resolver->flush();
        $this->applier->apply();
    }
}
