<?php

declare(strict_types=1);

namespace App\Services\Emails;

use App\Enums\NotificationSeverity;
use App\Models\AiAction;
use App\Models\Conversation;
use App\Models\Inventory;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Currency\CurrencyCatalog;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Builds the presentation data for a branded email.
 *
 * A notification row stores only the facts needed for the in-app copy. This
 * builder turns those facts into the richer structure the branded layout needs:
 * a heading, a status badge, ordered summary rows, tables, short notes and a
 * call to action. Where the notification carries a record id, the record is
 * re-read inside its own tenant context so the email shows current, real data
 * rather than a value assembled at dispatch time.
 *
 * It deliberately never invents a figure. Missing records fall back to the
 * scalar data written when the event was raised; a missing value is omitted
 * rather than rendered as a placeholder.
 */
final class EmailContentBuilder
{
    public function __construct(private readonly CurrencyCatalog $currencies) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Notification $notification, User $recipient): array
    {
        return $this->buildEvent(
            eventKey: $notification->event_key,
            locale: $this->locale($recipient),
            data: is_array($notification->data) ? $notification->data : [],
            actionUrl: $notification->action_url,
            severity: $notification->severity,
            tenantId: $notification->tenant_id !== null ? (int) $notification->tenant_id : null,
            title: $notification->title,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function buildEvent(
        string $eventKey,
        string $locale,
        array $data = [],
        ?string $actionUrl = null,
        ?NotificationSeverity $severity = null,
        ?int $tenantId = null,
        ?string $title = null,
    ): array {
        $group = $this->groupFor($eventKey);

        $content = match ($group) {
            'orders' => $this->orderContent($eventKey, $locale, $data, $severity, $tenantId),
            'inventory' => $this->inventoryContent($eventKey, $locale, $data, $severity, $tenantId),
            'messages' => $this->messageContent($eventKey, $locale, $data, $severity, $tenantId),
            'ai' => $this->aiContent($eventKey, $locale, $data, $severity, $tenantId),
            'reports' => $this->reportContent($eventKey, $locale, $data, $severity),
            'announcements' => $this->announcementContent($eventKey, $locale, $data, $severity),
            default => $this->genericContent($locale, $data, $title),
        };

        $preferenceDriven = $this->isPreferenceDriven($eventKey);

        return $this->presentation($locale, $group, $content, $actionUrl, $severity, $preferenceDriven);
    }

    /**
     * Build one account or security message.
     *
     * Account messages are not notification rows and have no preference switch:
     * they are transactional by definition and always sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function buildAccount(string $template, string $locale, array $data = [], ?string $actionUrl = null): array
    {
        $heading = $this->accountHeading($template, $locale, $data);
        $intro = $this->line('account.'.$template.'_intro', $locale, $data);

        $rows = [];

        foreach ($this->accountRows($template, $locale, $data) as $label => $value) {
            if ($value !== null && $value !== '') {
                $rows[] = ['label' => $label, 'value' => $value];
            }
        }

        $notes = [];
        $securityNote = null;
        $tone = in_array($template, ['new_device', 'password_changed'], true) ? 'warning' : 'success';

        if ($template === 'password_changed') {
            $securityNote = $this->line('account.password_changed_note', $locale);
        }

        if ($template === 'new_device') {
            $securityNote = $this->line('account.new_device_note', $locale);
        }

        if ($securityNote === null) {
            $securityNote = $this->line('account.security_note', $locale);
        }

        $expiry = match ($template) {
            'invitation' => 'account.invitation_expiry',
            'verify' => 'account.verify_expiry',
            'reset' => 'account.reset_expiry',
            default => null,
        };

        if ($expiry !== null) {
            $notes[] = $this->line($expiry, $locale, $data);
        }

        if ($template === 'role_changed') {
            $role = (string) ($data['role'] ?? '');
            $granted = (bool) ($data['granted'] ?? true);
            $notes[] = $this->line(
                $granted ? 'account.role_granted' : 'account.role_revoked',
                $locale,
                ['role' => $role],
            );
        }

        return [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'group' => 'account',
            'heading' => $heading,
            'intro' => $intro,
            'status' => ['label' => $this->statusLabel($locale, $tone === 'warning' ? 'warning' : 'success'), 'tone' => $tone],
            'eyebrow' => $this->line('brand.name', $locale),
            'summary' => $rows,
            'summary_title' => $rows !== [] ? $this->line('account.details', $locale) : null,
            'tables' => [],
            'notes' => $notes,
            'cta' => $actionUrl !== null && $actionUrl !== '' && $this->hasAccountCta($template)
                ? [
                    'url' => $this->absoluteUrl($actionUrl),
                    'label' => $this->line('account.'.$template.'_cta', $locale),
                    'note' => null,
                    'tone' => 'primary',
                ]
                : null,
            'preferences_url' => null,
            'unsubscribe_url' => null,
            'show_preferences' => false,
            'show_unsubscribe' => false,
            'security_note' => $securityNote,
            'preheader' => $intro !== '' ? $intro : $heading,
        ];
    }

    /**
     * The layout group a catalogue key belongs to.
     */
    public function groupFor(string $eventKey): string
    {
        return match (true) {
            str_starts_with($eventKey, 'tenant.order') => 'orders',
            str_starts_with($eventKey, 'tenant.inventory') => 'inventory',
            str_starts_with($eventKey, 'tenant.conversation'), str_starts_with($eventKey, 'tenant.message') => 'messages',
            str_starts_with($eventKey, 'tenant.ai_action'), str_starts_with($eventKey, 'tenant.approval') => 'ai',
            str_starts_with($eventKey, 'tenant.report') => 'reports',
            str_starts_with($eventKey, 'tenant.announcement') => 'announcements',
            str_starts_with($eventKey, 'account.') => 'account',
            default => 'generic',
        };
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function presentation(
        string $locale,
        string $group,
        array $content,
        ?string $actionUrl,
        ?NotificationSeverity $severity,
        bool $preferenceDriven,
    ): array {
        $content['locale'] = $locale;
        $content['dir'] = $locale === 'ar' ? 'rtl' : 'ltr';
        $content['group'] = $group;

        if (! isset($content['status'])) {
            $content['status'] = [
                'label' => $this->line('status.'.($severity?->value ?? 'info'), $locale),
                'tone' => $severity?->value ?? 'info',
            ];
        }

        $content['cta'] = $content['cta'] ?? null;

        if ($content['cta'] === null && $actionUrl !== null && $actionUrl !== '') {
            $content['cta'] = [
                'url' => $this->absoluteUrl($actionUrl),
                'label' => $this->line('common.view_in_app', $locale),
                'note' => null,
                'tone' => 'primary',
            ];
        }

        if (isset($content['cta']['url'])) {
            $content['cta']['url'] = $this->absoluteUrl((string) $content['cta']['url']);
        }

        $content['preferences_url'] = $preferenceDriven
            ? $this->absoluteUrl('/settings/notifications')
            : null;
        $content['show_preferences'] = $preferenceDriven;
        $content['unsubscribe_url'] = $content['unsubscribe_url'] ?? null;
        $content['show_unsubscribe'] = (bool) ($content['show_unsubscribe'] ?? false);
        $content['preheader'] = $content['preheader'] ?? ($content['intro'] ?? $content['heading'] ?? '');
        $content['notes'] = array_values(array_filter((array) ($content['notes'] ?? [])));
        $content['tables'] = $content['tables'] ?? [];
        $content['summary'] = $content['summary'] ?? [];
        $content['security_note'] = $content['security_note'] ?? null;

        return $content;
    }

    private function isPreferenceDriven(string $eventKey): bool
    {
        return str_starts_with($eventKey, 'tenant.')
            && ! str_starts_with($eventKey, 'tenant.announcement.maintenance');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function orderContent(
        string $eventKey,
        string $locale,
        array $data,
        ?NotificationSeverity $severity,
        ?int $tenantId,
    ): array {
        $order = $this->order($data, $tenantId);
        $number = $order?->order_number ?? (string) ($data['order_number'] ?? '');

        [$headingKey, $introKey] = match ($eventKey) {
            Events::TENANT_ORDER_PAID => ['orders.paid_heading', 'orders.paid_intro'],
            Events::TENANT_ORDER_SHIPPED => ['orders.shipped_heading', 'orders.shipped_intro'],
            Events::TENANT_ORDER_DELIVERED => ['orders.delivered_heading', 'orders.delivered_intro'],
            Events::TENANT_ORDER_CANCELLED => ['orders.cancelled_heading', 'orders.cancelled_intro'],
            Events::TENANT_ORDER_REFUNDED => ['orders.refunded_heading', 'orders.refunded_intro'],
            Events::TENANT_ORDER_STATUS_CHANGED => ['orders.status_heading', 'orders.status_intro'],
            default => ['orders.created_heading', 'orders.created_intro'],
        };

        $statusValue = $order?->status?->value ?? (string) ($data['status_value'] ?? (string) ($data['to'] ?? ''));
        $statusLabel = $statusValue !== '' && Lang::has('email.order_status.'.$statusValue)
            ? $this->line('order_status.'.$statusValue, $locale)
            : (string) ($data['status'] ?? $data['to'] ?? '');

        $heading = $this->line($headingKey, $locale, [
            'number' => $number,
            'status' => $statusLabel,
        ]);

        $rows = [];
        $rows[] = $this->row('common.order_number', $locale, $number);
        $rows[] = $this->row('common.order_date', $locale, $order?->placed_at?->format('Y-m-d H:i'));
        $rows[] = $this->row('common.customer', $locale, $order?->customer_name ?? ($data['customer'] ?? null));
        $rows[] = $this->row('common.store', $locale, $order?->store?->name);
        $rows[] = $this->row('common.status', $locale, $statusLabel);

        if ($order !== null) {
            $rows[] = $this->row('common.payment_status', $locale, $this->line('payment_status.'.$order->payment_status->value, $locale));
            $rows[] = $this->row('common.subtotal', $locale, $this->money($order->subtotal, $order->currency));
            $rows[] = $this->row('common.discount', $locale, $this->money($order->discount_total, $order->currency));
            $rows[] = $this->row('common.shipping', $locale, $this->money($order->shipping_total, $order->currency));
            $rows[] = $this->row('common.tax', $locale, $this->money($order->tax_total, $order->currency));
        }

        $rows[] = $this->row(
            'common.total',
            $locale,
            $order !== null ? $this->money($order->grand_total, $order->currency) : ($data['total'] ?? null),
        );

        $tables = [];
        $notes = [];

        if ($order !== null) {
            $items = $order->items->take(20);

            if ($items->isNotEmpty()) {
                $tables[] = [
                    'title' => $this->line('orders.lines', $locale),
                    'columns' => [
                        $this->line('common.product', $locale),
                        $this->line('common.sku', $locale),
                        $this->line('common.quantity', $locale),
                        $this->line('common.unit_price', $locale),
                        $this->line('common.line_total', $locale),
                    ],
                    'rows' => $items->map(fn ($item): array => [
                        (string) $item->name,
                        (string) ($item->sku ?? $this->line('common.empty', $locale)),
                        (string) $item->quantity,
                        $this->money($item->unit_price, $order->currency),
                        $this->money($item->line_total, $order->currency),
                    ])->all(),
                ];
            } else {
                $notes[] = $this->line('orders.no_items', $locale);
            }
        } else {
            $notes[] = $this->line('orders.no_items', $locale);
        }

        if ($eventKey === Events::TENANT_ORDER_REFUNDED) {
            $notes[] = $this->line('orders.refund_note', $locale);
        }

        return [
            'heading' => $heading,
            'intro' => $this->line($introKey, $locale, [
                'number' => $number,
                'status' => $statusLabel,
            ]),
            'status' => [
                'label' => $statusLabel !== '' ? $statusLabel : $this->statusLabel($locale, $severity?->value ?? 'info'),
                'tone' => $this->toneForSeverity($severity),
            ],
            'summary' => array_values(array_filter($rows)),
            'summary_title' => $this->line('orders.summary', $locale),
            'tables' => $tables,
            'notes' => $notes,
            'cta' => [
                'url' => $this->absoluteUrl('/orders/'.$number),
                'label' => $this->line('orders.cta', $locale),
                'note' => null,
                'tone' => 'primary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function inventoryContent(
        string $eventKey,
        string $locale,
        array $data,
        ?NotificationSeverity $severity,
        ?int $tenantId,
    ): array {
        $inventory = $this->inventory($data, $tenantId);
        $product = $inventory?->product?->name ?? (string) ($data['product'] ?? '');
        $available = $inventory?->available() ?? (int) ($data['quantity'] ?? 0);

        [$headingKey, $introKey] = match ($eventKey) {
            Events::TENANT_INVENTORY_OUT_OF_STOCK => ['inventory.out_of_stock_heading', 'inventory.out_of_stock_intro'],
            Events::TENANT_INVENTORY_RESTOCK_REMINDER => ['inventory.restock_heading', 'inventory.restock_intro'],
            Events::TENANT_INVENTORY_ADJUSTED => ['inventory.adjusted_heading', 'inventory.adjusted_intro'],
            default => ['inventory.low_stock_heading', 'inventory.low_stock_intro'],
        };

        $rows = [
            $this->row('common.product', $locale, $product),
            $this->row('common.sku', $locale, $inventory?->variant?->sku ?? ($data['sku'] ?? null)),
            $this->row('common.store', $locale, $inventory?->store?->name ?? ($data['store'] ?? null)),
            $this->row('common.available', $locale, (string) $available),
            $this->row('common.on_hand', $locale, $inventory?->quantity !== null ? (string) $inventory->quantity : ($data['on_hand'] ?? null)),
            $this->row('common.reserved', $locale, $inventory?->reserved_quantity !== null ? (string) $inventory->reserved_quantity : ($data['reserved'] ?? null)),
            $this->row('common.threshold', $locale, $inventory?->low_stock_threshold !== null ? (string) $inventory->low_stock_threshold : ($data['threshold'] ?? null)),
        ];

        $notes = [];

        if ($eventKey === Events::TENANT_INVENTORY_ADJUSTED) {
            $rows[] = $this->row('inventory.previous_quantity', $locale, isset($data['previous_quantity']) ? (string) $data['previous_quantity'] : null);
            $rows[] = $this->row('inventory.new_quantity', $locale, isset($data['new_quantity']) ? (string) $data['new_quantity'] : null);
            $rows[] = $this->row('common.change', $locale, isset($data['delta']) ? $this->signedNumber((int) $data['delta']) : null);
            $rows[] = $this->row('common.reason', $locale, $data['reason'] ?? null);
        }

        if ($eventKey === Events::TENANT_INVENTORY_RESTOCK_REMINDER) {
            $notes[] = $this->line('inventory.restock_note', $locale);
        }

        return [
            'heading' => $this->line($headingKey, $locale, ['product' => $product]),
            'intro' => $this->line($introKey, $locale, ['product' => $product]),
            'status' => [
                'label' => $this->statusLabel($locale, $severity?->value ?? 'warning'),
                'tone' => $this->toneForSeverity($severity),
            ],
            'summary' => array_values(array_filter($rows)),
            'summary_title' => $this->line('inventory.summary', $locale),
            'tables' => [],
            'notes' => $notes,
            'cta' => [
                'url' => $this->absoluteUrl('/inventory'),
                'label' => $this->line('inventory.cta', $locale),
                'note' => null,
                'tone' => 'primary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function messageContent(
        string $eventKey,
        string $locale,
        array $data,
        ?NotificationSeverity $severity,
        ?int $tenantId,
    ): array {
        $conversation = $this->conversation($data, $tenantId);
        $customer = $conversation?->customer?->name ?? (string) ($data['customer'] ?? '');

        [$headingKey, $introKey] = match ($eventKey) {
            Events::TENANT_CONVERSATION_ASSIGNED => ['messages.assigned_heading', 'messages.assigned_intro'],
            Events::TENANT_MESSAGE_FAILED => ['messages.failed_heading', 'messages.failed_intro'],
            Events::TENANT_CONVERSATION_ATTENTION => ['messages.attention_heading', 'messages.attention_intro'],
            default => ['messages.received_heading', 'messages.received_intro'],
        };

        $rows = [
            $this->row('common.customer', $locale, $customer),
            $this->row('messages.conversation', $locale, $conversation?->subject),
            $this->row('messages.preview', $locale, $data['preview'] ?? null),
        ];

        if ($eventKey === Events::TENANT_MESSAGE_FAILED) {
            $rows[] = $this->row('messages.failure_reason', $locale, $data['reason'] ?? null);
        }

        return [
            'heading' => $this->line($headingKey, $locale, ['customer' => $customer]),
            'intro' => $this->line($introKey, $locale, ['customer' => $customer]),
            'status' => [
                'label' => $this->statusLabel($locale, $severity?->value ?? 'info'),
                'tone' => $this->toneForSeverity($severity),
            ],
            'summary' => array_values(array_filter($rows)),
            'summary_title' => $this->line('orders.summary', $locale),
            'tables' => [],
            'notes' => [$this->line('messages.private_note', $locale)],
            'cta' => [
                'url' => $this->absoluteUrl('/conversations'),
                'label' => $this->line('messages.cta', $locale),
                'note' => null,
                'tone' => 'primary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function aiContent(
        string $eventKey,
        string $locale,
        array $data,
        ?NotificationSeverity $severity,
        ?int $tenantId,
    ): array {
        $action = $this->aiAction($data, $tenantId);
        $summary = $action?->summary ?: (string) ($data['summary'] ?? '');

        [$headingKey, $introKey, $ctaKey] = match ($eventKey) {
            Events::TENANT_AI_ACTION_APPROVED => ['ai.approved_heading', 'ai.approved_intro', 'ai.cta_view'],
            Events::TENANT_AI_ACTION_REJECTED => ['ai.rejected_heading', 'ai.rejected_intro', 'ai.cta_view'],
            Events::TENANT_AI_ACTION_FAILED => ['ai.failed_heading', 'ai.failed_intro', 'ai.cta_view'],
            default => ['ai.awaiting_heading', 'ai.awaiting_intro', 'ai.cta_review'],
        };

        $rows = [
            $this->row('ai.action_description', $locale, $summary),
            $this->row('common.status', $locale, $action?->status?->label() ?? ($data['status'] ?? null)),
            $this->row('common.requested_by', $locale, $action?->proposer?->name),
            $this->row('common.reviewed_by', $locale, $action?->approver?->name),
            $this->row('common.deadline', $locale, $action?->expires_at?->format('Y-m-d H:i')),
        ];

        if ($eventKey === Events::TENANT_AI_ACTION_REJECTED) {
            $rows[] = $this->row('ai.rejection_reason', $locale, $action?->rejection_reason ?? ($data['reason'] ?? null));
        }

        if ($eventKey === Events::TENANT_AI_ACTION_FAILED) {
            $rows[] = $this->row('common.reason', $locale, $action?->error ?? ($data['reason'] ?? null));
        }

        return [
            'heading' => $this->line($headingKey, $locale),
            'intro' => $this->line($introKey, $locale),
            'status' => [
                'label' => $this->statusLabel($locale, $severity?->value ?? 'warning'),
                'tone' => $this->toneForSeverity($severity),
            ],
            'summary' => array_values(array_filter($rows)),
            'summary_title' => $this->line('ai.summary', $locale),
            'tables' => [],
            'notes' => [$this->line('ai.ai_note', $locale)],
            'cta' => [
                'url' => $this->absoluteUrl('/ai/approvals'),
                'label' => $this->line($ctaKey, $locale),
                'note' => null,
                'tone' => 'primary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function reportContent(
        string $eventKey,
        string $locale,
        array $data,
        ?NotificationSeverity $severity,
    ): array {
        [$headingKey, $introKey] = match ($eventKey) {
            Events::TENANT_REPORT_MONTHLY => ['reports.monthly_heading', 'reports.monthly_intro'],
            Events::TENANT_REPORT_PERFORMANCE => ['reports.performance_heading', 'reports.performance_intro'],
            default => ['reports.weekly_heading', 'reports.weekly_intro'],
        };

        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $metrics = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $orders = (int) ($metrics['orders'] ?? $data['orders'] ?? 0);

        $rows = [
            $this->row('reports.orders', $locale, (string) $orders),
            $this->row('reports.net_revenue', $locale, $this->moneyOrNull($metrics['net_revenue'] ?? $data['net_revenue'] ?? null, $currency)),
            $this->row('reports.gross_profit', $locale, $this->moneyOrNull($metrics['gross_profit'] ?? null, $currency)),
            $this->row('reports.avg_order', $locale, $this->moneyOrNull($metrics['avg_order'] ?? null, $currency)),
            $this->row('reports.units', $locale, isset($metrics['units']) ? (string) (int) $metrics['units'] : null),
            $this->row('reports.refunds', $locale, isset($metrics['refunds']) ? (string) (int) $metrics['refunds'] : null),
            $this->row(
                'reports.cost_coverage',
                $locale,
                isset($metrics['cost_coverage']) ? number_format((float) $metrics['cost_coverage'], 1).'%' : null,
            ),
        ];

        $tables = [];
        $products = is_array($data['products'] ?? null) ? $data['products'] : [];

        if ($products !== []) {
            $tables[] = [
                'title' => $this->line('reports.top_products', $locale),
                'columns' => [
                    $this->line('common.product', $locale),
                    $this->line('reports.units', $locale),
                    $this->line('reports.net_revenue', $locale),
                ],
                'rows' => array_map(fn (array $product): array => [
                    (string) ($product['name'] ?? ''),
                    (string) (int) ($product['units'] ?? 0),
                    $this->moneyOrNull($product['net_sales'] ?? null, $currency) ?? $this->line('common.not_available', $locale),
                ], array_slice($products, 0, 5)),
            ];
        }

        $notes = [];

        if (($data['has_data'] ?? $orders > 0) === false) {
            $notes[] = $this->line('reports.no_data', $locale);
        }

        $notes[] = $this->line('reports.report_note', $locale, [
            'currency' => $currency,
        ]);

        return [
            'heading' => $this->line($headingKey, $locale),
            'intro' => $this->line($introKey, $locale),
            'status' => [
                'label' => $this->statusLabel($locale, $severity?->value ?? 'info'),
                'tone' => $this->toneForSeverity($severity),
            ],
            'summary' => array_values(array_filter($rows)),
            'summary_title' => $this->line('reports.summary', $locale),
            'tables' => $tables,
            'notes' => $notes,
            'cta' => [
                'url' => $this->absoluteUrl('/reports'),
                'label' => $this->line('reports.cta', $locale),
                'note' => null,
                'tone' => 'primary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function announcementContent(
        string $eventKey,
        string $locale,
        array $data,
        ?NotificationSeverity $severity,
    ): array {
        $maintenance = $eventKey === Events::TENANT_ANNOUNCEMENT_MAINTENANCE;
        $heading = (string) ($data['title'] ?? '');
        $intro = (string) ($data['summary'] ?? $data['body'] ?? '');

        if ($maintenance) {
            $heading = $heading !== '' ? $heading : $this->line('announcements.maintenance_heading', $locale);
            $intro = $intro !== '' ? $intro : $this->line('announcements.maintenance_intro', $locale);
        } else {
            $heading = $heading !== '' ? $heading : $this->line('reports.performance_heading', $locale);
            $intro = $intro !== '' ? $intro : $this->line('announcements.product_intro', $locale);
        }

        $notes = $maintenance ? [] : [$this->line('announcements.unsubscribe_note', $locale)];

        return [
            'heading' => $heading,
            'intro' => $intro,
            'status' => [
                'label' => $this->statusLabel($locale, $severity?->value ?? 'info'),
                'tone' => $this->toneForSeverity($severity),
            ],
            'summary' => [],
            'summary_title' => null,
            'tables' => [],
            'notes' => $notes,
            'cta' => [
                'url' => $this->absoluteUrl((string) ($data['cta_url'] ?? ($maintenance ? '/status' : '/whats-new'))),
                'label' => $this->line($maintenance ? 'announcements.maintenance_cta' : 'announcements.cta', $locale),
                'note' => null,
                'tone' => 'primary',
            ],
            'unsubscribe_url' => $maintenance ? null : $this->absoluteUrl('/settings/notifications'),
            'show_unsubscribe' => ! $maintenance,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function genericContent(string $locale, array $data, ?string $title): array
    {
        $heading = $title !== null && $title !== ''
            ? $title
            : (string) ($data['title'] ?? $this->line('brand.name', $locale));

        $intro = (string) ($data['body'] ?? $data['summary'] ?? '');

        return [
            'heading' => $heading,
            'intro' => $intro,
            'summary' => [],
            'summary_title' => null,
            'tables' => [],
            'notes' => [],
            'cta' => null,
        ];
    }

    private function order(array $data, ?int $tenantId): ?Order
    {
        $id = isset($data['order_id']) ? (int) $data['order_id'] : null;

        if ($id === null || $tenantId === null) {
            return null;
        }

        return $this->withinTenant($tenantId, fn (): ?Order => Order::query()
            ->with(['items', 'store', 'customer'])
            ->find($id));
    }

    private function inventory(array $data, ?int $tenantId): ?Inventory
    {
        $id = isset($data['inventory_id']) ? (int) $data['inventory_id'] : null;

        if ($id === null || $tenantId === null) {
            return null;
        }

        return $this->withinTenant($tenantId, fn (): ?Inventory => Inventory::query()
            ->with(['product', 'variant', 'store'])
            ->find($id));
    }

    private function conversation(array $data, ?int $tenantId): ?Conversation
    {
        $id = isset($data['conversation_id']) ? (int) $data['conversation_id'] : null;

        if ($id === null || $tenantId === null) {
            return null;
        }

        return $this->withinTenant($tenantId, fn (): ?Conversation => Conversation::query()
            ->with('customer')
            ->find($id));
    }

    private function aiAction(array $data, ?int $tenantId): ?AiAction
    {
        $id = isset($data['ai_action_id']) ? (int) $data['ai_action_id'] : null;

        if ($id === null || $tenantId === null) {
            return null;
        }

        return $this->withinTenant($tenantId, fn (): ?AiAction => AiAction::query()
            ->with(['proposer', 'approver'])
            ->find($id));
    }

    /**
     * Run a read inside the tenant that owns the notification.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn|null
     */
    private function withinTenant(int $tenantId, callable $callback): mixed
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        try {
            return app(TenantContext::class)->run($tenant, $callback);
        } catch (Throwable) {
            return null;
        }
    }

    private function locale(User $recipient): string
    {
        $locale = (string) ($recipient->locale ?: config('dukavo.locales.default', 'en'));

        return in_array($locale, (array) config('dukavo.locales.supported', ['en', 'ar']), true)
            ? $locale
            : (string) config('dukavo.locales.default', 'en');
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function line(string $key, string $locale, array $replace = []): string
    {
        return (string) Lang::get('email.'.$key, $replace, $locale);
    }

    private function statusLabel(string $locale, string $tone): string
    {
        return $this->line('status.'.($this->toneForSeverityValue($tone)), $locale);
    }

    private function toneForSeverity(?NotificationSeverity $severity): string
    {
        return $this->toneForSeverityValue($severity?->value ?? 'info');
    }

    private function toneForSeverityValue(string $value): string
    {
        return in_array($value, ['info', 'success', 'warning', 'critical'], true) ? $value : 'info';
    }

    /**
     * @return array{label: string, value: string}|null
     */
    private function row(string $key, string $locale, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ['label' => $this->line($key, $locale), 'value' => (string) $value];
    }

    private function money(mixed $amount, ?string $currency): string
    {
        $code = strtoupper((string) ($currency ?: config('dukavo.currencies.default', 'USD')));
        $decimals = $this->currencies->supports($code) ? $this->currencies->decimals($code) : 2;

        return number_format((float) $amount, $decimals, '.').' '.$code;
    }

    private function moneyOrNull(mixed $amount, ?string $currency): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return $this->money($amount, $currency);
    }

    private function signedNumber(int $value): string
    {
        return $value > 0 ? '+'.$value : (string) $value;
    }

    private function absoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('dukavo.urls.frontend'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function accountHeading(string $template, string $locale, array $data): string
    {
        $key = match ($template) {
            'welcome', 'invitation', 'verify', 'reset', 'password_changed', 'new_device', 'role_changed' => 'account.'.$template.'_heading',
            default => null,
        };

        if ($key === null || ! Lang::has('email.'.$key)) {
            return $this->line('brand.name', $locale);
        }

        return $this->line($key, $locale, $this->scalarStrings($data));
    }

    private function hasAccountCta(string $template): bool
    {
        return in_array($template, [
            'welcome', 'invitation', 'verify', 'reset', 'password_changed', 'new_device', 'role_changed',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function accountRows(string $template, string $locale, array $data): array
    {
        $rows = [];

        if (isset($data['name'])) {
            $rows[$this->line('common.name', $locale)] = (string) $data['name'];
        }

        if (isset($data['email'])) {
            $rows[$this->line('common.email', $locale)] = (string) $data['email'];
        }

        if (isset($data['tenant'])) {
            $rows[$this->line('common.workspace', $locale)] = (string) $data['tenant'];
        }

        if (isset($data['role'])) {
            $rows[$this->line('common.role', $locale)] = (string) $data['role'];
        }

        if (isset($data['device'])) {
            $rows[$this->line('common.device', $locale)] = (string) $data['device'];
        }

        if (isset($data['time'])) {
            $rows[$this->line('common.time', $locale)] = (string) $data['time'];
        }

        if (isset($data['ip_address'])) {
            $rows[$this->line('common.ip_address', $locale)] = (string) $data['ip_address'];
        }

        if (isset($data['expires'])) {
            $rows[$this->line('account.expires', $locale)] = (string) $data['expires'];
        }

        if (isset($data['inviter'])) {
            $rows[$this->line('common.requested_by', $locale)] = (string) $data['inviter'];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function scalarStrings(array $data): array
    {
        $strings = [];

        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $strings[$key] = (string) $value;
            }
        }

        return $strings;
    }
}
