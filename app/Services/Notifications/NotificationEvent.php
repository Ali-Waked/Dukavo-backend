<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationScope;
use App\Enums\NotificationSeverity;

/**
 * The definition of one notification event.
 *
 * An event says who its audience is (`scope` + `permission`), which preference
 * switch governs it, which outbound channels it may use, and the localized copy
 * it renders. Keeping all of that in one place means adding an event is a data
 * change, not a new branch in the dispatcher, and the settings screen derives
 * its switches from the same source the sender obeys.
 */
final readonly class NotificationEvent
{
    /**
     * @param  list<NotificationChannel>  $channels
     * @param  array<string, array{title: string, body: string}>  $messages
     */
    public function __construct(
        public string $key,
        public NotificationScope $scope,
        public string $category,
        public NotificationSeverity $severity,
        public array $channels = [],
        public ?string $permission = null,
        public ?string $preference = null,
        public ?int $cooldownSeconds = null,
        public array $messages = [],
    ) {}

    /**
     * Render the title and body in the recipient's language.
     *
     * A locale without a translation falls back to the configured default, so a
     * newly supported language can ship before every event is translated
     * without ever rendering an empty notification.
     *
     * @param  array<string, scalar|null>  $data
     * @return array{title: string, body: string}
     */
    public function message(string $locale, array $data): array
    {
        $fallback = (string) config('dukavo.locales.default', 'en');
        $template = $this->messages[$locale] ?? $this->messages[$fallback] ?? [
            'title' => $this->key,
            'body' => '',
        ];

        return [
            'title' => $this->interpolate($template['title'], $data),
            'body' => $this->interpolate($template['body'], $data),
        ];
    }

    /**
     * @param  array<string, scalar|null>  $data
     */
    private function interpolate(string $template, array $data): string
    {
        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $match) use ($data): string {
                $value = $data[$match[1]] ?? '';

                return is_scalar($value) ? (string) $value : '';
            },
            $template,
        );
    }
}
