<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Models\Notification;
use App\Models\NotificationDevice;
use Illuminate\Console\Command;

/**
 * Applies the notification retention policy.
 *
 * MySQL is the authoritative store, so it must not grow forever. Three
 * independent windows are applied, in a safe order:
 *
 *  - archived rows (hidden on request, or an outbound-only copy) go first;
 *  - read rows then age out of the bell;
 *  - unread rows are never pruned by age, because an unpaid order or a
 *    stock-out the merchant has not seen yet is exactly what they must not lose.
 *
 * Devices follow the same idea: rejected tokens are deleted after a grace
 * period, while an installation that has not checked in for months is revoked
 * rather than deleted, preserving the explanation for why it stopped.
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune
        {--dry-run : Report what would be removed without changing anything}';

    protected $description = 'Prune notifications and push devices past their retention windows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $archivedDays = max(1, (int) config('dukavo.notifications.retention.archived_days', 30));
        $readDays = max(1, (int) config('dukavo.notifications.retention.read_days', 180));
        $deviceDays = max(1, (int) config('dukavo.notifications.retention.device_days', 180));

        $archivedCutoff = now()->subDays($archivedDays);
        $readCutoff = now()->subDays($readDays);
        $deviceCutoff = now()->subDays($deviceDays);

        $archived = Notification::query()
            ->whereNotNull('archived_at')
            ->where('archived_at', '<', $archivedCutoff);

        $read = Notification::query()
            ->whereNull('archived_at')
            ->whereNotNull('read_at')
            ->where('read_at', '<', $readCutoff);

        $revokedDevices = NotificationDevice::query()
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', $deviceCutoff);

        $staleDevices = NotificationDevice::query()
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query
                ->where('last_seen_at', '<', $deviceCutoff)
                ->orWhere(fn ($inner) => $inner
                    ->whereNull('last_seen_at')
                    ->where('created_at', '<', $deviceCutoff)));

        if ($dryRun) {
            $this->info(sprintf(
                'Would delete %d archived notification(s), %d read notification(s), %d revoked device(s) and revoke %d stale device(s).',
                $archived->count(),
                $read->count(),
                $revokedDevices->count(),
                $staleDevices->count(),
            ));

            return self::SUCCESS;
        }

        $archivedCount = $archived->delete();
        $readCount = $read->delete();
        $deviceCount = $revokedDevices->delete();
        $revokedCount = $staleDevices->update(['revoked_at' => now()]);

        $this->info(sprintf(
            'Deleted %d archived notification(s), %d read notification(s), %d revoked device(s); revoked %d stale device(s).',
            $archivedCount,
            $readCount,
            $deviceCount,
            $revokedCount,
        ));

        return self::SUCCESS;
    }
}
