<?php

declare(strict_types=1);

namespace App\Console\Commands\Email;

use App\Enums\NotificationSeverity;
use App\Models\Tenant;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends a product, release or maintenance announcement.
 *
 * Product and release announcements obey the recipient's `product_news`
 * preference and carry an unsubscribe link, so they are opt-out marketing in the
 * honest sense. A maintenance notice is operational and cannot be muted; it is
 * the one announcement that must reach every workspace member who can see the
 * dashboard.
 */
class SendAnnouncement extends Command
{
    protected $signature = 'email:announce
        {--tenant= : Send to one tenant id; omit for every active tenant}
        {--type=product : product, release or maintenance}
        {--title= : Announcement title}
        {--summary= : Short announcement body}
        {--url= : Optional call-to-action URL}';

    protected $description = 'Send a branded announcement to workspace members';

    public function handle(NotificationDispatcher $notifications, TenantContext $tenants): int
    {
        $type = (string) $this->option('type');
        $title = trim((string) $this->option('title'));
        $summary = trim((string) $this->option('summary'));

        $eventKey = match ($type) {
            'product' => Events::TENANT_ANNOUNCEMENT_PRODUCT,
            'release' => Events::TENANT_ANNOUNCEMENT_RELEASE,
            'maintenance' => Events::TENANT_ANNOUNCEMENT_MAINTENANCE,
            default => null,
        };

        if ($eventKey === null) {
            $this->error('The announcement type must be product, release or maintenance.');

            return self::INVALID;
        }

        if ($title === '' || $summary === '') {
            $this->error('Both --title and --summary are required so the announcement has real content.');

            return self::INVALID;
        }

        $query = Tenant::query()->active()->orderBy('id');
        $tenantId = $this->option('tenant');

        if ($tenantId !== null && $tenantId !== '') {
            $query->whereKey((int) $tenantId);
        }

        $sent = 0;
        $failed = 0;
        $data = [
            'title' => $title,
            'summary' => $summary,
            'cta_url' => $this->option('url') ?: null,
        ];

        $query->chunkById(50, function ($chunk) use ($notifications, $tenants, $eventKey, $type, $data, &$sent, &$failed): void {
            foreach ($chunk as $tenant) {
                try {
                    $sent += $tenants->run($tenant, fn (): int => $notifications->dispatch(new NotificationIntent(
                        eventKey: $eventKey,
                        tenantId: (int) $tenant->getKey(),
                        dedupeKey: $eventKey.':'.$tenant->getKey().':'.md5($data['title'].'|'.$data['summary']),
                        data: $data,
                        actionUrl: $data['cta_url'],
                        severity: $type === 'maintenance' ? NotificationSeverity::Warning : NotificationSeverity::Info,
                    )));
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error(sprintf('Tenant %d failed: %s', $tenant->getKey(), $exception->getMessage()));
                }
            }
        });

        $this->info(sprintf('Sent %s announcement to %d recipient(s); %d tenant(s) failed.', $type, $sent, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
