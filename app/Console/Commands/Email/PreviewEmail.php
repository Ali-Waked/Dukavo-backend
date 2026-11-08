<?php

declare(strict_types=1);

namespace App\Console\Commands\Email;

use App\Services\Emails\EmailContentBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Renders a branded email to disk for design review.
 *
 * This is the previewable example the team can open in a browser before an
 * event is ever sent, in either language. It uses sample business data only; it
 * never reads a real recipient or sends anything.
 */
class PreviewEmail extends Command
{
    protected $signature = 'email:preview
        {template=tenant.order.paid : Notification event key, or account.<template>}
        {--locale=en : en or ar}
        {--output= : Directory to write the preview files into}';

    protected $description = 'Render a branded email template to HTML and text for review';

    public function handle(EmailContentBuilder $builder): int
    {
        $template = (string) $this->argument('template');
        $locale = (string) $this->option('locale');

        if (! in_array($locale, ['en', 'ar'], true)) {
            $this->error('The locale must be en or ar.');

            return self::INVALID;
        }

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $content = str_starts_with($template, 'account.')
                ? $builder->buildAccount(
                    template: substr($template, strlen('account.')),
                    locale: $locale,
                    data: $this->accountData(),
                    actionUrl: '/dashboard',
                )
                : $builder->buildEvent(
                    eventKey: $template,
                    locale: $locale,
                    data: $this->eventData($template),
                    actionUrl: '/dashboard',
                );

            $html = view('emails.layout', ['content' => $content])->render();
            $text = view('emails.text', ['content' => $content])->render();
        } finally {
            app()->setLocale($previous);
        }

        $directory = (string) ($this->option('output') ?: storage_path('app/email-previews'));
        File::ensureDirectoryExists($directory);

        $base = $directory.'/'.str_replace(['.', '/'], '-', $template).'-'.$locale;
        File::put($base.'.html', $html);
        File::put($base.'.txt', $text);

        $this->info(sprintf('Wrote %s.html and %s.txt', $base, $base));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventData(string $eventKey): array
    {
        $order = [
            'order_number' => 'ORD-1042',
            'customer' => 'Layla Hassan',
            'total' => '249.00 USD',
            'status' => 'Paid',
            'status_value' => 'paid',
            'store' => 'Dukavo Demo Store',
        ];

        return match (true) {
            str_starts_with($eventKey, 'tenant.order') => $order,
            str_starts_with($eventKey, 'tenant.inventory') => [
                'product' => 'Olive oil, 500 ml',
                'sku' => 'OIL-500',
                'store' => 'Dukavo Demo Store',
                'quantity' => 4,
                'available' => 4,
                'on_hand' => 6,
                'reserved' => 2,
                'threshold' => 10,
                'previous_quantity' => 12,
                'new_quantity' => 6,
                'delta' => -6,
                'reason' => 'Stock take',
            ],
            str_starts_with($eventKey, 'tenant.conversation'), str_starts_with($eventKey, 'tenant.message') => [
                'customer' => 'Amina Yusuf',
                'preview' => 'Is the olive oil back in stock? I would like to order three bottles.',
                'reason' => 'The channel provider rejected the recipient.',
            ],
            str_starts_with($eventKey, 'tenant.ai_action'), str_starts_with($eventKey, 'tenant.approval') => [
                'summary' => 'Move 6 units of OIL-500 from the main warehouse to the Riyadh store.',
                'status' => 'Awaiting review',
                'reason' => 'The inventory row changed after the proposal was made.',
            ],
            str_starts_with($eventKey, 'tenant.report') => [
                'period' => '2026-11-01 – 2026-11-07',
                'currency' => 'USD',
                'orders' => 42,
                'net_revenue' => '18,420.00',
                'has_data' => true,
                'summary' => [
                    'orders' => 42,
                    'net_revenue' => 18420.0,
                    'gross_profit' => 6120.5,
                    'avg_order' => 438.57,
                    'units' => 137,
                    'refunds' => 2,
                    'cost_coverage' => 94.2,
                ],
                'products' => [
                    ['name' => 'Olive oil, 500 ml', 'units' => 48, 'net_sales' => 3120.0],
                    ['name' => 'Date syrup', 'units' => 31, 'net_sales' => 1840.0],
                ],
            ],
            str_starts_with($eventKey, 'tenant.announcement') => [
                'title' => 'Stock counts are now faster',
                'summary' => 'Scan a barcode to adjust stock, with a full audit trail for every change.',
                'cta_url' => '/whats-new',
            ],
            default => [
                'title' => 'A Dukavo update',
                'body' => 'This is a preview of a generic branded notification.',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function accountData(): array
    {
        return [
            'name' => 'Layla Hassan',
            'email' => 'layla@example.com',
            'tenant' => 'Dukavo Demo Store',
            'role' => 'Store manager',
            'inviter' => 'Omar Khalid',
            'minutes' => 60,
            'expires' => '2026-11-08 12:00',
            'device' => 'Chrome on macOS',
            'time' => '2026-11-08 09:14',
            'ip_address' => '203.0.113.10',
            'granted' => '1',
        ];
    }
}
