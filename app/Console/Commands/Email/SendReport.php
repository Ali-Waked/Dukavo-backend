<?php

declare(strict_types=1);

namespace App\Console\Commands\Email;

use App\Models\Tenant;
use App\Services\Reporting\ScheduledReportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends the weekly, monthly or performance business report.
 *
 * Each tenant is evaluated inside its own context, so the report can only ever
 * read that tenant's orders and can only address that tenant's recipients. The
 * dispatcher's dedupe key makes a repeated run on the same period harmless.
 */
class SendReport extends Command
{
    protected $signature = 'email:report
        {period=weekly : weekly, monthly or performance}
        {--tenant= : Limit the run to one tenant id}';

    protected $description = 'Send a scheduled business report to each active tenant';

    public function handle(ScheduledReportService $reports, TenantContext $tenants): int
    {
        $period = (string) $this->argument('period');

        if (! in_array($period, ['weekly', 'monthly', 'performance'], true)) {
            $this->error('The period must be weekly, monthly or performance.');

            return self::INVALID;
        }

        $query = Tenant::query()->active()->orderBy('id');
        $tenantId = $this->option('tenant');

        if ($tenantId !== null && $tenantId !== '') {
            $query->whereKey((int) $tenantId);
        }

        $recipients = 0;
        $tenantCount = 0;
        $failed = 0;

        $query->chunkById(50, function ($chunk) use ($reports, $tenants, $period, &$recipients, &$tenantCount, &$failed): void {
            foreach ($chunk as $tenant) {
                $tenantCount++;

                try {
                    $recipients += $tenants->run(
                        $tenant,
                        fn (): int => $reports->dispatch($tenant, $period),
                    );
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error(sprintf('Tenant %d failed: %s', $tenant->getKey(), $exception->getMessage()));
                }
            }
        });

        $this->info(sprintf(
            'Sent %d %s report recipient(s) across %d tenant(s); %d failed.',
            $recipients,
            $period,
            $tenantCount,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
