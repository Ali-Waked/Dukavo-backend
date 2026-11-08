<?php

declare(strict_types=1);

namespace App\Console\Commands\Email;

use App\Models\Inventory;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends a restock reminder for inventory that is still running low.
 *
 * The notification dispatcher owns the seven-day cooldown, so the command can
 * run daily without nagging: a successful reminder for a row is not repeated
 * until the window has passed, and rows that recovered are skipped here.
 */
class SendRestockReminders extends Command
{
    protected $signature = 'email:restock-reminders
        {--tenant= : Limit the run to one tenant id}';

    protected $description = 'Send restock reminders for inventory that is still low';

    public function handle(InventoryService $inventory, TenantContext $tenants): int
    {
        $query = Tenant::query()->active()->orderBy('id');
        $tenantId = $this->option('tenant');

        if ($tenantId !== null && $tenantId !== '') {
            $query->whereKey((int) $tenantId);
        }

        $sent = 0;
        $failed = 0;

        $query->chunkById(50, function ($chunk) use ($inventory, $tenants, &$sent, &$failed): void {
            foreach ($chunk as $tenant) {
                try {
                    $sent += $tenants->run($tenant, function () use ($inventory): int {
                        $count = 0;

                        Inventory::query()
                            ->lowStock()
                            ->orderBy('id')
                            ->chunkById(100, function ($rows) use ($inventory, &$count): void {
                                foreach ($rows as $row) {
                                    if ($inventory->sendRestockReminder($row)) {
                                        $count++;
                                    }
                                }
                            });

                        return $count;
                    });
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error(sprintf('Tenant %d failed: %s', $tenant->getKey(), $exception->getMessage()));
                }
            }
        });

        $this->info(sprintf('Sent %d restock reminder(s); %d tenant(s) failed.', $sent, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
