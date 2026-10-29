<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Support\AiCapability;

/**
 * A language-model backend that can describe itself.
 *
 * {@see LanguageModel} covers the wire contract — send a prompt, get an answer.
 * This contract adds the two things a multi-provider application needs and that
 * a single-provider one never had to think about: *which* provider is in use,
 * and *what it can do*.
 *
 * Features must not assume that every provider offers the same capabilities. A
 * use case declares what it needs; the registry satisfies the request from
 * whichever configured provider can serve it, and refuses when none can.
 */
interface AiProvider extends LanguageModel
{
    /**
     * The provider's stable key, e.g. `xai` or `openai`.
     *
     * This is recorded on every usage row and shown to operators, so it must
     * never contain a credential or an endpoint that embeds one.
     */
    public function provider(): string;

    /**
     * The capabilities this provider genuinely supports.
     *
     * @return list<AiCapability>
     */
    public function capabilities(): array;

    /**
     * Whether this provider supports a capability.
     */
    public function supports(AiCapability $capability): bool;
}
