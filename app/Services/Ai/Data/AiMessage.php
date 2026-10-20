<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * One message in a model prompt.
 *
 * Roles are restricted to those xAI accepts so a caller cannot smuggle an
 * unsupported role through; the content is always plain text, never remote HTML.
 */
final readonly class AiMessage
{
    public const ROLE_SYSTEM = 'system';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public function __construct(
        public string $role,
        public string $content,
    ) {}

    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ROLE_ASSISTANT, $content);
    }

    /**
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
