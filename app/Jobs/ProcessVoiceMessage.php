<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\VoiceMessage;
use App\Services\Voice\VoiceMessageProcessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Transcribe and extract one voice note in the background.
 *
 * The job carries identifiers, never models: a worker restores the tenant from
 * the payload before loading the voice row, so a queued job cannot read another
 * workspace's data.
 */
final class ProcessVoiceMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A retry would transcribe the same audio again and could double-bill. A
     * failure is visible in the inbox and is retried explicitly by a person.
     */
    public int $tries = 1;

    public function __construct(
        private readonly int $voiceMessageId,
        private readonly int $tenantId,
    ) {}

    public function handle(TenantContext $tenants, VoiceMessageProcessor $processor): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $tenants->run($tenant, function () use ($processor): void {
            $voice = VoiceMessage::query()->find($this->voiceMessageId);

            if ($voice instanceof VoiceMessage) {
                $processor->process($voice);
            }
        });
    }
}
