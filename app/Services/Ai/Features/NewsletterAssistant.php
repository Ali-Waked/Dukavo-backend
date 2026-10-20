<?php

declare(strict_types=1);

namespace App\Services\Ai\Features;

use App\Services\Ai\AiGuard;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Support\PromptGuard;
use App\Support\HtmlSanitizer;

/**
 * Helps author Dukavo's newsletter editions: an outline, a first draft, or a
 * revision of an existing draft.
 *
 * The model returns HTML, but the output is untrusted like any other generated
 * text, so it is passed through the same {@see HtmlSanitizer} the manual editor
 * uses before it is returned. Nothing is saved and nothing is published here —
 * the writer decides what, if anything, to keep.
 */
final class NewsletterAssistant extends Feature
{
    private const FEATURE = 'content';

    /** @var list<string> */
    public const ACTIONS = ['outline', 'draft', 'improve'];

    public function __construct(
        LanguageModel $model,
        AiGuard $guard,
        AiUsageRecorder $usage,
        PromptGuard $prompts,
        private readonly HtmlSanitizer $sanitizer,
    ) {
        parent::__construct($model, $guard, $usage, $prompts);
    }

    /**
     * @return array{title: string, excerpt: string, body: string, usage: array{model: string, input_tokens: int, output_tokens: int, total_tokens: int}}
     */
    public function generate(string $action, ?string $brief, ?string $audience, ?string $body, int $userId): array
    {
        $completion = $this->run(
            self::FEATURE,
            $this->systemPrompt($action),
            $this->userPrompt($action, $brief, $audience, $body),
            tenantId: null,
            userId: $userId,
            schema: self::schema(),
            schemaName: 'newsletter_content',
        );

        $structured = $completion->structured ?? [];

        return [
            'title' => mb_substr($this->prompts->clean((string) ($structured['title'] ?? '')), 0, 200),
            'excerpt' => mb_substr($this->prompts->clean((string) ($structured['excerpt'] ?? '')), 0, 320),
            'body' => $this->sanitizer->clean((string) ($structured['body'] ?? '')),
            'usage' => $this->usageSummary($completion),
        ];
    }

    private function systemPrompt(string $action): string
    {
        return implode(' ', [
            'You are Dukavo\'s editorial assistant, helping write a newsletter edition.',
            $this->task($action),
            $this->prompts->guidance(),
            'Write in a clear, practical, confident voice aimed at small online merchants.',
            'Return clean semantic HTML using only these tags: <p>, <strong>, <em>, <ul>, <ol>, <li>, <h2>, <h3>, <blockquote> and <a href="…">.',
            'Do not include inline styles, classes, scripts, images or iframes.',
            'Do not invent statistics, customer quotes, testimonials, prices or dates.',
            'Return a JSON object with a title, an excerpt of at most 320 characters, and the body HTML.',
        ]);
    }

    private function task(string $action): string
    {
        return match ($action) {
            'outline' => 'Produce a detailed outline: the title, the excerpt, and a body that is a structured list of the sections to write, with a one-line note on each.',
            'improve' => 'Improve and tighten the provided draft while keeping its meaning, structure and links; fix clarity, flow and consistency.',
            default => 'Produce a complete first draft that a human editor will refine.',
        };
    }

    private function userPrompt(string $action, ?string $brief, ?string $audience, ?string $body): string
    {
        $lines = [
            'Task: '.$action,
            'Audience: '.$this->prompts->clean((string) ($audience ?? 'Dukavo newsletter subscribers')),
            '',
            'Brief (what the edition should be about):',
            $this->prompts->wrap('brief', $brief, 2000),
        ];

        if ($action === 'improve') {
            $lines[] = '';
            $lines[] = 'Existing draft to improve:';
            $lines[] = $this->prompts->wrap('draft', $body, 6000);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'A concise headline.'],
                'excerpt' => ['type' => 'string', 'description' => 'A one or two sentence summary, at most 320 characters.'],
                'body' => ['type' => 'string', 'description' => 'The edition body as semantic HTML.'],
            ],
            'required' => ['title', 'excerpt', 'body'],
            'additionalProperties' => false,
        ];
    }
}
