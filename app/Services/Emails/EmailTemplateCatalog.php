<?php

declare(strict_types=1);

namespace App\Services\Emails;

use App\Services\Notifications\NotificationEventCatalog;
use Illuminate\Support\Facades\Lang;

/**
 * The authoritative list of emails a platform operator may customise.
 *
 * A template is either an account/security message or a catalogue event. The
 * catalogue owns the default copy, the placeholders an operator may use, and
 * whether the template may be disabled at all. Security mail is never optional.
 */
final class EmailTemplateCatalog
{
    public const string KIND_ACCOUNT = 'account';

    public const string KIND_NOTIFICATION = 'notification';

    public const string BROADCAST = 'platform.broadcast';

    /**
     * Account templates and the placeholders their copy understands.
     *
     * @var array<string, list<string>>
     */
    private const array ACCOUNT_VARIABLES = [
        'welcome' => ['name'],
        'invitation' => ['name', 'tenant', 'inviter', 'role', 'minutes'],
        'verify' => ['name', 'minutes'],
        'reset' => ['name', 'minutes'],
        'password_changed' => ['name'],
        'new_device' => ['name', 'device', 'time', 'ip_address'],
        'role_changed' => ['name', 'tenant', 'role'],
        'two_factor_enabled' => ['name'],
        'two_factor_disabled' => ['name'],
        'recovery_codes_regenerated' => ['name'],
    ];

    /**
     * Optional templates may be switched off by an operator. Everything else
     * either protects the account or is required for the product to work.
     *
     * @var list<string>
     */
    private const array OPTIONAL_KEYS = [
        'tenant.announcement.product',
        'tenant.announcement.release',
        'tenant.report.weekly',
        'tenant.report.monthly',
        'tenant.report.performance',
        self::BROADCAST,
    ];

    public function __construct(private readonly NotificationEventCatalog $events) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $templates = [];

        foreach (array_keys(self::ACCOUNT_VARIABLES) as $template) {
            $key = 'account.'.$template;

            $templates[$key] = [
                'key' => $key,
                'kind' => self::KIND_ACCOUNT,
                'group' => 'account',
                'optional' => $this->isOptional($key),
                'variables' => array_values(array_unique(['name', ...self::ACCOUNT_VARIABLES[$template]])),
                'label' => $this->line('account.'.$template.'_heading', 'en'),
                'subjects' => [
                    'en' => $this->line('account.'.$template.'_heading', 'en'),
                    'ar' => $this->line('account.'.$template.'_heading', 'ar'),
                ],
                'headings' => [
                    'en' => $this->line('account.'.$template.'_heading', 'en'),
                    'ar' => $this->line('account.'.$template.'_heading', 'ar'),
                ],
                'intros' => [
                    'en' => $this->line('account.'.$template.'_intro', 'en'),
                    'ar' => $this->line('account.'.$template.'_intro', 'ar'),
                ],
                'sample' => $this->sampleData($key),
            ];
        }

        foreach ($this->events->all() as $event) {
            $templates[$event->key] = [
                'key' => $event->key,
                'kind' => self::KIND_NOTIFICATION,
                'group' => $this->groupFor($event->key),
                'optional' => $this->isOptional($event->key),
                'variables' => $this->variablesIn($event->messages),
                'label' => (string) ($event->messages['en']['title'] ?? $event->key),
                'subjects' => [
                    'en' => (string) ($event->messages['en']['title'] ?? $event->key),
                    'ar' => (string) ($event->messages['ar']['title'] ?? $event->key),
                ],
                'headings' => [
                    'en' => (string) ($event->messages['en']['title'] ?? $event->key),
                    'ar' => (string) ($event->messages['ar']['title'] ?? $event->key),
                ],
                'intros' => [
                    'en' => (string) ($event->messages['en']['body'] ?? ''),
                    'ar' => (string) ($event->messages['ar']['body'] ?? ''),
                ],
                'sample' => $this->sampleData($event->key),
            ];
        }

        // Campaign copy lives on the campaign, not in the event catalogue, but
        // its email is still a template an operator may customise or disable.
        $templates[self::BROADCAST] = [
            'key' => self::BROADCAST,
            'kind' => self::KIND_NOTIFICATION,
            'group' => 'announcements',
            'optional' => true,
            'variables' => ['title', 'body', 'name', 'workspace'],
            'label' => 'Platform broadcast',
            'subjects' => ['en' => '{title}', 'ar' => '{title}'],
            'headings' => ['en' => '{title}', 'ar' => '{title}'],
            'intros' => ['en' => '{body}', 'ar' => '{body}'],
            'sample' => [
                'title' => 'A new Dukavo feature is live',
                'body' => 'Hello {name}, the feature is now available in {workspace}.',
                'name' => 'Sara',
                'workspace' => 'Sara Store',
            ],
        ];

        ksort($templates);

        return $templates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    public function isOptional(string $key): bool
    {
        return in_array($key, self::OPTIONAL_KEYS, true);
    }

    /**
     * Placeholders extracted from a catalogue message, controlled so an
     * operator cannot introduce a token the renderer does not understand.
     *
     * @param  array<string, array{title: string, body: string}>  $messages
     * @return list<string>
     */
    private function variablesIn(array $messages): array
    {
        $variables = [];

        foreach ($messages as $copy) {
            foreach (['title', 'body'] as $field) {
                preg_match_all('/\{(\w+)\}/', (string) ($copy[$field] ?? ''), $matches);
                $variables = [...$variables, ...$matches[1]];
            }
        }

        return array_values(array_unique($variables));
    }

    public function groupFor(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'account.') => 'account',
            str_starts_with($key, 'tenant.order') => 'orders',
            str_starts_with($key, 'tenant.inventory') => 'inventory',
            str_starts_with($key, 'tenant.conversation'), str_starts_with($key, 'tenant.message') => 'messages',
            str_starts_with($key, 'tenant.ai_action'), str_starts_with($key, 'tenant.approval') => 'ai',
            str_starts_with($key, 'tenant.report') => 'reports',
            str_starts_with($key, 'tenant.announcement'), $key === self::BROADCAST => 'announcements',
            default => 'generic',
        };
    }

    /**
     * Realistic shape without invented financial totals. Report previews ask
     * the builder for the empty state, so no fabricated revenue is shown.
     *
     * @return array<string, mixed>
     */
    public function sampleData(string $key): array
    {
        $sample = [
            'name' => 'Sara',
            'tenant' => 'Sara Store',
            'role' => 'Manager',
            'inviter' => 'Omar',
            'minutes' => 60,
            'device' => 'Pixel 8',
            'time' => '09:30',
            'ip_address' => '203.0.113.10',
            'order_number' => 'ORD-000123',
            'customer' => 'Sample customer',
            'total' => '120.00 USD',
            'product' => 'Sample product',
            'sku' => 'SKU-001',
            'store' => 'Main store',
            'quantity' => 3,
            'status' => 'Pending',
            'status_value' => 'pending',
        ];

        if (str_starts_with($key, 'tenant.report')) {
            return [
                'currency' => 'USD',
                'has_data' => false,
                'summary' => [],
                'products' => [],
            ];
        }

        if ($key === self::BROADCAST) {
            return [
                'title' => 'A new Dukavo feature is live',
                'body' => 'Hello {name}, the feature is now available in {workspace}.',
                'name' => 'Sara',
                'workspace' => 'Sara Store',
            ];
        }

        return $sample;
    }

    private function line(string $key, string $locale): string
    {
        $value = (string) Lang::get('email.'.$key, [], $locale);

        // Account translations use Laravel's `:name` syntax while notification
        // copy uses `{name}`. The admin UI exposes one placeholder syntax, so
        // normalise the account defaults here.
        return (string) preg_replace('/:(\w+)/', '{$1}', $value);
    }
}
