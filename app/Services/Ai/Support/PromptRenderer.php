<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use App\Enums\AiTask;
use App\Models\AiPromptTemplate;
use App\Models\AiPromptVersion;

/**
 * Renders a stored prompt template for one task and purpose.
 *
 * Resolution order: the template addressed by (task, key), its active
 * version (or the newest when no pointer is set), the locale asked for,
 * then the platform default locale, then whichever locale exists. A task
 * with no template, or a template with no version, renders as null — the
 * caller then uses its built-in prompt, so an unconfigured deployment
 * keeps serving exactly as before.
 *
 * Only the variables the version declares are interpolated, and every
 * value is cleaned and bounded through {@see PromptGuard} first: a prompt
 * template is a trusted instruction, and nothing a caller supplies may
 * smuggle its own substitutions into it.
 */
final class PromptRenderer
{
    public function __construct(private readonly PromptGuard $guard) {}

    /**
     * @param  array<string, string|numeric|null>  $variables
     * @return array{system_prompt: string, user_prompt: ?string, locale: string, version: int}|null
     */
    public function render(AiTask $task, string $key, ?string $locale = null, array $variables = []): ?array
    {
        $template = AiPromptTemplate::query()
            ->where('key', $key)
            ->whereHas('task', fn ($query) => $query->where('key', $task->value))
            ->with(['activeVersion'])
            ->first();

        if ($template === null) {
            return null;
        }

        $version = $this->resolveVersion($template, $locale);

        if ($version === null) {
            return null;
        }

        return [
            'system_prompt' => $this->interpolate((string) $version->system_prompt, $version, $variables),
            'user_prompt' => $version->user_prompt !== null
                ? $this->interpolate((string) $version->user_prompt, $version, $variables)
                : null,
            'locale' => (string) $version->locale,
            'version' => (int) $version->version,
        ];
    }

    /**
     * The active version when it carries an acceptable locale, otherwise
     * the newest version in the preferred locale, then the default locale,
     * then the newest of any locale. Null when the template has no
     * versions at all.
     */
    private function resolveVersion(AiPromptTemplate $template, ?string $locale): ?AiPromptVersion
    {
        $active = $template->activeVersion;

        if ($active !== null && ($locale === null || $active->locale === $locale)) {
            return $active;
        }

        $preferred = $locale ?? $this->preferredLocale();

        foreach ([$preferred, $this->defaultLocale()] as $candidate) {
            $version = $template->versions()
                ->where('locale', $candidate)
                ->first();

            if ($version !== null) {
                return $version;
            }
        }

        return $template->versions()->first();
    }

    /**
     * Replace whitelisted `{{name}}` placeholders, ignoring spacing.
     *
     * A placeholder the version did not declare stays literal, so a
     * template can never be coerced into interpolating a variable the
     * operator never approved.
     *
     * @param  array<string, string|numeric|null>  $variables
     */
    private function interpolate(string $prompt, AiPromptVersion $version, array $variables): string
    {
        $allowed = $version->variables ?? [];

        if (! is_array($allowed) || $allowed === [] || $variables === []) {
            return $prompt;
        }

        $replacements = [];

        foreach ($allowed as $name) {
            if (! is_string($name) || ! array_key_exists($name, $variables)) {
                continue;
            }

            $value = $variables[$name];
            $clean = $this->guard->truncate($this->guard->clean((string) $value), 2000);
            $replacements['{{'.$name.'}}'] = $clean;
            $replacements['{{ '.$name.' }}'] = $clean;
        }

        return $replacements === [] ? $prompt : strtr($prompt, $replacements);
    }

    /**
     * The locale of the current request, when it carries one.
     */
    private function preferredLocale(): string
    {
        return (string) app()->getLocale();
    }

    private function defaultLocale(): string
    {
        $locale = config('dukavo.locales.default');

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }
}
