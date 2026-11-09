<?php

declare(strict_types=1);

namespace App\Services\Emails;

use App\Enums\AuditAction;
use App\Models\NotificationEmailTemplate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes platform email-template overrides.
 *
 * The catalogue owns the defaults; this service merges a stored row over them,
 * renders controlled placeholders, and enforces the rule that only optional
 * templates can be disabled. Subjects and body copy are always plain text:
 * there is no path that stores executable HTML.
 */
final class EmailTemplateService
{
    public function __construct(
        private readonly EmailTemplateCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Every template with its defaults, stored override and effective copy.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $overrides = NotificationEmailTemplate::query()->get()->keyBy('template_key');

        $entries = [];

        foreach ($this->catalog->all() as $key => $template) {
            $entries[] = $this->merge($template, $overrides->get($key));
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        $template = $this->catalog->find($key);

        if ($template === null) {
            return null;
        }

        return $this->merge($template, NotificationEmailTemplate::query()->where('template_key', $key)->first());
    }

    public function isEnabled(string $key): bool
    {
        $template = $this->catalog->find($key);

        // An event that is not in the email catalogue is not configurable and
        // must keep its existing behaviour.
        if ($template === null) {
            return true;
        }

        if (! $template['optional']) {
            return true;
        }

        $override = NotificationEmailTemplate::query()->where('template_key', $key)->first();

        return $override?->enabled ?? true;
    }

    /**
     * Resolve the subject for a rendered email.
     *
     * `$fallback` is the notification's already-localised title, which is the
     * default for catalogue events. Account templates use their translated
     * heading as the fallback.
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function subject(string $key, string $locale, array $variables = [], ?string $fallback = null): string
    {
        $template = $this->catalog->find($key);

        if ($template === null) {
            return $fallback ?? '';
        }

        $override = $this->overrideValue($key, 'subject', $locale);

        if ($override !== null && $override !== '') {
            return $this->interpolate($override, $variables);
        }

        if ($fallback !== null && $fallback !== '') {
            return $fallback;
        }

        return $this->interpolate((string) ($template['subjects'][$locale] ?? $template['subjects']['en']), $variables);
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>|null  $draft  unsaved overrides for previews
     * @return array<string, mixed>
     */
    public function applyToContent(
        string $key,
        string $locale,
        array $content,
        array $variables = [],
        ?array $draft = null,
    ): array {
        $template = $this->catalog->find($key);

        if ($template === null) {
            return $content;
        }

        $heading = $draft['heading'] ?? $this->overrideValue($key, 'heading', $locale);
        $intro = $draft['intro'] ?? $this->overrideValue($key, 'intro', $locale);

        if (is_string($heading) && trim($heading) !== '') {
            $content['heading'] = $this->interpolate($heading, $variables);
        }

        if (is_string($intro) && trim($intro) !== '') {
            $content['intro'] = $this->interpolate($intro, $variables);
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $key, array $data, User $actor): array
    {
        $template = $this->catalog->find($key);

        if ($template === null) {
            throw ValidationException::withMessages(['template' => 'Unknown email template.']);
        }

        $allowed = $template['variables'];
        $subject = $this->localized($data['subject'] ?? null, $allowed, 180);
        $heading = $this->localized($data['heading'] ?? null, $allowed, 180);
        $intro = $this->localized($data['intro'] ?? null, $allowed, 2000);

        $enabled = $template['optional']
            ? (bool) ($data['enabled'] ?? true)
            : true;

        $override = DB::transaction(function () use ($key, $subject, $heading, $intro, $enabled, $actor): NotificationEmailTemplate {
            $existing = NotificationEmailTemplate::query()->where('template_key', $key)->first();
            $before = $existing?->only(['enabled', 'subject', 'heading', 'intro']) ?? [];

            $override = NotificationEmailTemplate::query()->updateOrCreate(
                ['template_key' => $key],
                [
                    'enabled' => $enabled,
                    'subject' => $subject === [] ? null : $subject,
                    'heading' => $heading === [] ? null : $heading,
                    'intro' => $intro === [] ? null : $intro,
                    'updated_by' => $actor->getKey(),
                ],
            );

            $this->audit->record(AuditAction::EmailTemplateUpdated, $override, [
                'old' => $before,
                'new' => $override->only(['enabled', 'subject', 'heading', 'intro']),
            ], $actor);

            return $override;
        });

        return $this->merge($template, $override);
    }

    /**
     * Delete the override so the shipped copy is used again.
     */
    public function restore(string $key, User $actor): void
    {
        if (! $this->catalog->has($key)) {
            throw ValidationException::withMessages(['template' => 'Unknown email template.']);
        }

        DB::transaction(function () use ($key, $actor): void {
            $existing = NotificationEmailTemplate::query()->where('template_key', $key)->first();

            if (! $existing instanceof NotificationEmailTemplate) {
                return;
            }

            $this->audit->record(AuditAction::EmailTemplateRestored, $existing, [
                'old' => $existing->only(['enabled', 'subject', 'heading', 'intro']),
            ], $actor);

            $existing->delete();
        });
    }

    /**
     * Replace only the controlled `{placeholder}` tokens.
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function interpolate(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $match) use ($variables): string {
                $value = $variables[$match[1]] ?? '';

                return is_scalar($value) ? (string) $value : '';
            },
            $text,
        );
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function merge(array $template, ?NotificationEmailTemplate $override): array
    {
        $stored = [
            'subject' => $override?->subject ?? [],
            'heading' => $override?->heading ?? [],
            'intro' => $override?->intro ?? [],
        ];

        $effective = [];

        foreach (['subject', 'heading', 'intro'] as $field) {
            foreach (['en', 'ar'] as $locale) {
                $value = $stored[$field][$locale] ?? null;
                $effective[$field][$locale] = is_string($value) && $value !== ''
                    ? $value
                    : (string) ($template[$field.'s'][$locale] ?? '');
            }
        }

        return [
            'key' => $template['key'],
            'kind' => $template['kind'],
            'group' => $template['group'],
            'optional' => (bool) $template['optional'],
            'enabled' => ! $template['optional'] || ($override?->enabled ?? true),
            'variables' => $template['variables'],
            'label' => $template['label'],
            'sample' => $template['sample'] ?? [],
            'defaults' => [
                'subject' => $template['subjects'],
                'heading' => $template['headings'],
                'intro' => $template['intros'],
            ],
            'overrides' => $stored,
            'effective' => $effective,
            'updated_at' => $override?->updated_at?->toIso8601String(),
            'updated_by' => $override?->updated_by,
        ];
    }

    private function overrideValue(string $key, string $field, string $locale): ?string
    {
        $override = NotificationEmailTemplate::query()->where('template_key', $key)->first();

        if (! $override instanceof NotificationEmailTemplate) {
            return null;
        }

        $values = $override->getAttribute($field);

        if (! is_array($values)) {
            return null;
        }

        $value = $values[$locale] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private function localized(mixed $input, array $allowed, int $maxLength): array
    {
        if (! is_array($input)) {
            return [];
        }

        $clean = [];

        foreach (['en', 'ar'] as $locale) {
            $value = $input[$locale] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = $this->plainText($value);

            if (mb_strlen($value) > $maxLength) {
                throw ValidationException::withMessages([
                    "{$locale}" => "The {$locale} copy may not be longer than {$maxLength} characters.",
                ]);
            }

            preg_match_all('/\{(\w+)\}/', $value, $matches);

            $unknown = array_values(array_diff(array_unique($matches[1]), $allowed));

            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    "{$locale}" => 'Unsupported placeholder(s): {'.implode('}, {', $unknown).'}.',
                ]);
            }

            $clean[$locale] = $value;
        }

        return $clean;
    }

    private function plainText(string $value): string
    {
        $value = strip_tags($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return trim($value);
    }
}
