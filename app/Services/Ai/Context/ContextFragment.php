<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Support\PromptGuard;

/**
 * One labelled piece of context on its way into a prompt.
 *
 * A fragment is data, never instruction: it is cleaned and delimited by
 * {@see PromptGuard} before it reaches the model, so
 * a product name or a customer message cannot smuggle a command into the
 * prompt.
 */
final readonly class ContextFragment
{
    public function __construct(
        public string $label,
        public string $content,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }
}
