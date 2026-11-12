<?php

use App\Models\HelpSearchLog;
use App\Models\Tenant;
use App\Services\Ai\Governance\AiActionService;
use App\Services\Automation\AutomationScheduleRunner;
use App\Services\Recovery\RecoveryDetectionService;
use App\Services\Voice\VoiceMediaRetention;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

Artisan::command('automation:run-scheduled {--scope=daily : daily, weekly or all} {--tenant= : Run for one tenant id} {--dry-run : Report without dispatching}', function () {
    $scope = (string) $this->option('scope');

    if (! in_array($scope, ['daily', 'weekly', 'all'], true)) {
        $this->error('The scope must be daily, weekly or all.');

        return 1;
    }

    $tenantId = $this->option('tenant');
    $stats = app(AutomationScheduleRunner::class)->run(
        $scope,
        $tenantId !== null ? (int) $tenantId : null,
        (bool) $this->option('dry-run'),
    );

    $this->info(sprintf(
        '%s %d automation event(s) for %d rule(s) across %d tenant(s); skipped %d rule(s).',
        $this->option('dry-run') ? 'Would dispatch' : 'Dispatched',
        $stats['events'],
        $stats['rules'],
        $stats['tenants'],
        $stats['skipped'],
    ));

    return 0;
})->purpose('Dispatch due daily, weekly and unpaid-order automation events');

/*
 * Help Center search logs. Popular and zero-result searches only matter
 * relatively recently, and every search writes a row, so the log is pruned in
 * the quiet hours before the analytics window drifts away from what is stored.
 */
Artisan::command('help:prune-search-logs {--days=90 : Retention period in days}', function () {
    $days = max(1, (int) $this->option('days'));

    $pruned = HelpSearchLog::query()
        ->where('created_at', '<', now()->subDays($days))
        ->delete();

    $this->info(sprintf('Pruned %d help search log(s) older than %d day(s).', $pruned, $days));
})->purpose('Prune expired Help Center search logs')->dailyAt('03:40')->withoutOverlapping();

/*
 * Email schedules.
 *
 * Reports run in the morning so they are waiting when the workspace opens.
 * Weekly reports cover the previous seven days; monthly and performance reports
 * run on the first of the month. Restock reminders are daily, with the
 * dispatcher's seven-day cooldown preventing a nagging repeat.
 */
Schedule::command('email:report weekly')->weeklyOn(1, '07:00')->withoutOverlapping();
Schedule::command('email:report monthly')->monthlyOn(1, '07:30')->withoutOverlapping();
Schedule::command('email:report performance')->monthlyOn(1, '08:00')->withoutOverlapping();
Schedule::command('email:restock-reminders')->dailyAt('09:00')->withoutOverlapping();

/*
 * Automation scheduler. The daily run also evaluates unpaid-order follow-ups;
 * the weekly run produces its own dated event. Both are idempotent inside the
 * engine, so a retried or duplicated scheduler invocation cannot double-send.
 */
Schedule::command('automation:run-scheduled --scope=daily')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('automation:run-scheduled --scope=weekly')->weeklyOn(1, '06:10')->withoutOverlapping();

/*
 * Notification housekeeping. Runs in the quiet hours so a large delete never
 * competes with the report or restock jobs above.
 */
Schedule::command('notifications:prune')->dailyAt('03:30')->withoutOverlapping();

/*
 * Campaign clock. A scheduled announcement is published when this runs, and a
 * published campaign is closed once its ledger has no work left.
 */
Schedule::command('notifications:campaigns')->everyMinute()->withoutOverlapping();
