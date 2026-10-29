<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Support\PromptGuard;

/**
 * The context a prompt is allowed to see.
 *
 * It records both what went in and what was left out. The exclusions are not
 * debugging noise: they are the record that a permission check or a tenant
 * boundary removed something, and they are what an operator or a test asserts
 * on to prove the boundary held.
 */
final readonly class AiContext
{
    /**
     * @param  list<ContextFragment>  $fragments
     * @param  array<string, string>  $excluded
     */
    public function __construct(
        private array $fragments,
        private array $excluded,
        private string $text,
    ) {}

    /**
     * The delimited, untrusted-data block for the prompt.
     */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * The labels that made it into the prompt.
     *
     * @return list<string>
     */
    public function included(): array
    {
        return array_map(static fn (ContextFragment $fragment): string => $fragment->label, $this->fragments);
    }

    /**
     * Labels that were withheld, and why.
     *
     * @return array<string, string>
     */
    public function excluded(): array
    {
        return $this->excluded;
    }

    public function isEmpty(): bool
    {
        return $this->fragments === [];
    }

    /**
     * Whether a label survived the authorisation and size checks.
     */
    public function contains(string $label): bool
    {
        return in_array($label, $this->included(), true);
    }

    /**
     * The prompt block, with the untrusted-data guidance in front of it.
     */
    public function toPromptSection(): string
    {
        if ($this->isEmpty()) {
            return 'No context is available for this request.';
        }

        return $this->text;
    }

    /**
     * @param  list<ContextFragment>  $fragments
     * @param  array<string, string>  $excluded
     */
    public static function build(PromptGuard $prompts, array $fragments, array $excluded): self
    {
        $text = '';

        foreach ($fragments as $fragment) {
            $text .= $prompts->wrap($fragment->label, $fragment->content)."\n\n";
        }

        return new self($fragments, $excluded, trim($text));
    }
}
