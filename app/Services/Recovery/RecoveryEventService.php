<?php

declare(strict_types=1);

namespace App\Services\Recovery;

use App\Enums\RecoveryEventType;
use App\Models\ConversationMessage;
use App\Models\RecoveryEvent;
use App\Models\RecoveryOpportunity;
use App\Models\User;

/**
 * Append-only funnel recording for recovery opportunities.
 *
 * The service is intentionally tiny: every workflow writes through it so the
 * event names and tenant stamping cannot drift between detection, outreach and
 * attribution.
 */
final class RecoveryEventService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        RecoveryOpportunity $opportunity,
        RecoveryEventType $type,
        ?User $actor = null,
        ?ConversationMessage $message = null,
        ?string $note = null,
        array $meta = [],
    ): RecoveryEvent {
        return RecoveryEvent::query()->create([
            'tenant_id' => $opportunity->tenant_id,
            'recovery_opportunity_id' => $opportunity->getKey(),
            'user_id' => $actor?->getKey(),
            'conversation_message_id' => $message?->getKey(),
            'type' => $type,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
