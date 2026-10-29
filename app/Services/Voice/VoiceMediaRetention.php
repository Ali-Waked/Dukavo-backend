<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Enums\VoiceMessageStatus;
use App\Models\Tenant;
use App\Models\VoiceMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes the original audio after its review window.
 *
 * The transcript, extraction and any reviewed cart remain. Keeping the link
 * between the message and the voice record means the inbox can explain that the
 * recording expired instead of rendering a broken player.
 */
final class VoiceMediaRetention
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{checked: int, purged: int, skipped: int, failed: int}
     */
    public function prune(int $days, bool $dryRun = false, ?int $tenantId = null): array
    {
        $cutoff = now()->subDays(max(1, $days));
        $totals = ['checked' => 0, 'purged' => 0, 'skipped' => 0, 'failed' => 0];

        $tenants = Tenant::query()
            ->when($tenantId !== null, fn ($query) => $query->whereKey($tenantId))
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            $this->tenants->run($tenant, function () use ($cutoff, $dryRun, &$totals): void {
                VoiceMessage::query()
                    ->whereNotNull('media_path')
                    ->whereNull('media_purged_at')
                    ->where('created_at', '<=', $cutoff)
                    ->whereIn('status', [
                        VoiceMessageStatus::Ready->value,
                        VoiceMessageStatus::Applied->value,
                        VoiceMessageStatus::Failed->value,
                    ])
                    ->orderBy('id')
                    ->chunkById(100, function ($voices) use ($dryRun, &$totals): void {
                        foreach ($voices as $voice) {
                            $totals['checked']++;

                            if ($dryRun) {
                                $totals['purged']++;

                                continue;
                            }

                            try {
                                $this->purge($voice);
                                $totals['purged']++;
                            } catch (Throwable $exception) {
                                report($exception);
                                $totals['failed']++;
                            }
                        }
                    });
            });
        }

        return $totals;
    }

    private function purge(VoiceMessage $voice): void
    {
        $path = $voice->media_path;

        if (! is_string($path) || $path === '') {
            return;
        }

        Storage::disk('local')->delete($path);

        $voice->forceFill([
            'media_path' => null,
            'media_purged_at' => now(),
        ])->save();

        $message = $voice->message;

        if ($message === null) {
            return;
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        $media = is_array($meta['media'] ?? null) ? $meta['media'] : null;

        if ($media !== null) {
            unset($media['path']);
            $media['purged_at'] = now()->toIso8601String();
            $meta['media'] = $media;
            $message->forceFill(['meta' => $meta])->save();
        }
    }
}
