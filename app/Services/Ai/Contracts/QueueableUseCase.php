<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

/**
 * A use case that may be run in the background.
 *
 * Some AI work takes long enough that it should not hold a request open:
 * summarising a long thread, transcribing a recording, reading a season of
 * history. Those use cases implement this contract and the application queues
 * them instead of waiting.
 *
 * The contract is deliberately small. The job restores the tenant context,
 * resolves the use case by key and hands it a payload; the use case decides
 * where its own result belongs, because only it knows its domain.
 */
interface QueueableUseCase
{
    /**
     * The use case's stable key, as registered in the catalogue.
     */
    public function useCaseKey(): string;

    /**
     * The payload this use case accepts.
     *
     * @return list<string>
     */
    public function queuedPayloadKeys(): array;

    /**
     * Run the use case in the background.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> A summary of what was done.
     */
    public function handleQueued(array $payload): array;
}
