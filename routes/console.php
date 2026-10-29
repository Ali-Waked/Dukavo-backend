<?php

use App\Models\Tenant;
use App\Services\Ai\Governance\AiActionService;
use App\Services\Recovery\RecoveryDetectionService;
use App\Services\Voice\VoiceMediaRetention;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:expire-actions {--limit=200}', function () {
    $expired = app(AiActionService::class)
        ->expireStale((int) $this->option('limit'));

    $this->info(sprintf('Expired %d stale AI action(s).', $expired));
})->purpose('Expire AI approvals whose review window has passed')->everyFiveMinutes();

Artisan::command('voice:prune-media {--days= : Retention period in days} {--tenant= : Prune one tenant id} {--dry-run : Report without deleting}', function () {
    $days = (int) ($this->option('days') ?: config('services.whatsapp.voice_media_retention_days', 30));
    $tenantId = $this->option('tenant');
    $dryRun = (bool) $this->option('dry-run');

    $stats = app(VoiceMediaRetention::class)->prune(
        $days,
        $dryRun,
        $tenantId !== null ? (int) $tenantId : null,
    );

    $this->info(sprintf(
        '%s %d expired voice recording(s); skipped %d, failed %d, checked %d.',
        $dryRun ? 'Would remove' : 'Removed',
        $stats['purged'],
        $stats['skipped'],
        $stats['failed'],
        $stats['checked'],
    ));

    return $stats['failed'] > 0 ? 1 : 0;
})->purpose('Remove voice-note audio after its retention period')->daily()->withoutOverlapping();

Artisan::command('recovery:detect {--tenant= : Detect for one tenant id} {--dry-run : Report without writing}', function () {
    $tenantId = $this->option('tenant');
    $dryRun = (bool) $this->option('dry-run');

    $query = Tenant::query()->active()->orderBy('id');

    if ($tenantId !== null) {
        $query->whereKey((int) $tenantId);
    }

    $totals = ['created' => 0, 'expired' => 0, 'reopened' => 0, 'skipped' => 0, 'failed' => 0];
    $found = 0;

    $query->chunkById(50, function ($tenants) use (&$totals, &$found, $dryRun): void {
        foreach ($tenants as $tenant) {
            $found++;
            $stats = app(RecoveryDetectionService::class)->detectForTenant($tenant, $dryRun);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + ($stats[$key] ?? 0);
            }
        }
    });

    if ($found === 0) {
        $this->warn('No active tenant matched.');

        return 1;
    }

    $this->info(sprintf(
        '%s %d opportunit%s, expired %d, reopened %d, skipped %d, failed %d across %d tenant(s).',
        $dryRun ? 'Would create' : 'Created',
        $totals['created'],
        $totals['created'] === 1 ? 'y' : 'ies',
        $totals['expired'],
        $totals['reopened'],
        $totals['skipped'],
        $totals['failed'],
        $found,
    ));

    return $totals['failed'] > 0 ? 1 : 0;
})->purpose('Detect conservative lost-sale opportunities and wake/expire work')->hourly()->withoutOverlapping();
