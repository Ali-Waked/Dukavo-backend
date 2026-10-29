<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Contracts;

use App\Enums\AiActionClassification;
use App\Models\User;

/**
 * Turns an approved proposal into a business effect.
 *
 * An executor is the only place where an AI-proposed action may change data,
 * and it may only do so through the application's own services. The executor
 * never receives a query, a table name or anything the model wrote as
 * instructions — it receives a payload the server has already resolved against
 * real records.
 *
 * Every executor declares the permission required to approve and run it and the
 * classification that determines its governance, so neither can drift per call
 * site.
 */
interface AiActionExecutor
{
    /**
     * The stable action type, e.g. `inventory.adjust`.
     */
    public function actionType(): string;

    /**
     * How much authority the action carries. Declared by the executor, never by
     * the model or the request.
     */
    public function classification(): AiActionClassification;

    /**
     * The permission a person must hold to approve and execute this action.
     */
    public function requiredPermission(): string;

    /**
     * Check and normalise a proposed payload.
     *
     * The payload arriving here is what a model proposed. It is untrusted: the
     * executor resolves it against real records and returns only what the
     * application vouches for.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolve(array $payload): array;

    /**
     * Re-check the critical conditions immediately before execution.
     *
     * Returns a fresh resolved payload. Throw when the world changed in a way
     * that would make the approved action wrong; the person must review it
     * again rather than have a stale decision applied.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function revalidate(array $payload): array;

    /**
     * Perform the action.
     *
     * @param  array<string, mixed>  $payload  The already-resolved payload.
     * @param  string  $idempotencyKey  Stable key for providers and ledgers that support one.
     * @return array<string, mixed> A result summary for the audit trail.
     */
    public function execute(array $payload, User $actor, string $idempotencyKey): array;
}
