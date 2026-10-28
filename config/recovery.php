<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Lost-sale recovery
|--------------------------------------------------------------------------
|
| Detection is deliberately conservative. A cart must still have lines and no
| order, or the customer's latest message must be an unanswered inbound message.
| Thresholds are in hours so a merchant can tune them without a deployment.
|
| `expires_after_days` is how long an unworked opportunity stays relevant. It is
| not a promise to the customer and never auto-sends anything.
|
*/

return [
    'enabled' => (bool) env('RECOVERY_ENABLED', true),

    // How far back detection may create a new opportunity.
    'lookback_days' => (int) env('RECOVERY_LOOKBACK_DAYS', 90),

    // A draft cart or unanswered message must be at least this old.
    'stale_after_hours' => (int) env('RECOVERY_STALE_AFTER_HOURS', 24),

    // How long an open opportunity remains workable before it expires.
    'expires_after_days' => (int) env('RECOVERY_EXPIRES_AFTER_DAYS', 30),

    // Suggested next follow-up after a successful contact. This is presented
    // to the agent, never used to send anything automatically.
    'follow_up_after_days' => (int) env('RECOVERY_FOLLOW_UP_AFTER_DAYS', 3),

    // Per-run safety cap so a large historical backlog cannot lock a worker.
    'max_per_run' => (int) env('RECOVERY_MAX_PER_RUN', 500),

    // A WhatsApp text is the only automated contact path. Templates are sent
    // through the existing provider gateway when the free-form window is closed.
    'allow_non_whatsapp_contact' => (bool) env('RECOVERY_ALLOW_NON_WHATSAPP', false),
];
