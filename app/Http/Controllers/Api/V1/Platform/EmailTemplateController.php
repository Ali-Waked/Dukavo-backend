<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PreviewEmailTemplateRequest;
use App\Http\Requests\Platform\TestEmailTemplateRequest;
use App\Http\Requests\Platform\UpdateEmailTemplateRequest;
use App\Mail\EmailTemplateTestMail;
use App\Services\Emails\EmailContentBuilder;
use App\Services\Emails\EmailTemplateCatalog;
use App\Services\Emails\EmailTemplateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Platform-only email template administration.
 *
 * Operators may change the subject, heading and intro, preview both locales,
 * send themselves a real test, disable an optional template, or restore the
 * shipped copy. Raw HTML is never accepted: the branded layout stays under
 * application control and only plain text with catalogue-approved placeholders
 * can be stored.
 */
class EmailTemplateController extends Controller
{
    public function __construct(
        private readonly EmailTemplateService $templates,
        private readonly EmailTemplateCatalog $catalog,
        private readonly EmailContentBuilder $builder,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success('', [
            'templates' => $this->templates->entries(),
            'locales' => ['en', 'ar'],
        ]);
    }

    public function show(string $template): JsonResponse
    {
        return ApiResponse::success('', $this->entry($template));
    }

    public function update(UpdateEmailTemplateRequest $request, string $template): JsonResponse
    {
        $this->entry($template);

        return ApiResponse::success(
            'Email template updated.',
            $this->templates->update($template, $request->validated(), $request->user()),
        );
    }

    public function restore(Request $request, string $template): JsonResponse
    {
        $this->entry($template);
        $this->templates->restore($template, $request->user());

        return ApiResponse::success('Email template restored.', $this->entry($template));
    }

    public function preview(PreviewEmailTemplateRequest $request, string $template): JsonResponse
    {
        $this->entry($template);

        $locale = (string) $request->input('locale', 'en');
        $draft = array_filter([
            'heading' => $request->input('heading.'.$locale),
            'intro' => $request->input('intro.'.$locale),
        ], static fn ($value) => is_string($value) && trim($value) !== '');

        $rendered = $this->render($template, $locale, $draft);

        return ApiResponse::success('', [
            'locale' => $locale,
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
            'content' => $rendered['content'],
        ]);
    }

    public function test(TestEmailTemplateRequest $request, string $template): JsonResponse
    {
        $entry = $this->entry($template);

        $locale = (string) $request->input('locale', 'en');
        $recipient = (string) ($request->input('email') ?: $request->user()->email);
        $rendered = $this->render($template, $locale);

        Mail::to($recipient)->send(new EmailTemplateTestMail(
            subjectLine: $rendered['subject'],
            bodyHtml: $rendered['html'],
            bodyText: $rendered['text'],
        ));

        return ApiResponse::success('Test email handed to the configured mail transport.', [
            'email' => $recipient,
            'locale' => $locale,
            'subject' => $rendered['subject'],
            'template' => $entry['key'],
            'transport' => (string) config('mail.default', 'smtp'),
            // Acceptance by the transport is not proof of delivery.
            'delivery_confirmed' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $template): array
    {
        $entry = $this->templates->find($template);

        if ($entry === null) {
            abort(404, 'Unknown email template.');
        }

        return $entry;
    }

    /**
     * @param  array<string, string>  $draft
     * @return array{subject: string, html: string, text: string, content: array<string, mixed>}
     */
    private function render(string $template, string $locale, array $draft = []): array
    {
        $entry = $this->entry($template);
        $data = $entry['sample'];
        $actionUrl = '/dashboard';

        $content = $entry['kind'] === EmailTemplateCatalog::KIND_ACCOUNT
            ? $this->builder->buildAccount(
                template: substr($template, strlen('account.')),
                locale: $locale,
                data: $data,
                actionUrl: $actionUrl,
                applyOverrides: false,
            )
            : $this->builder->buildEvent(
                eventKey: $template,
                locale: $locale,
                data: $data,
                actionUrl: $actionUrl,
                title: isset($data['title']) ? (string) $data['title'] : null,
                applyOverrides: false,
            );

        $variables = $this->variables($entry, $content);

        $content = $this->templates->applyToContent($template, $locale, $content, $variables, $draft);

        $subject = $this->templates->subject(
            key: $template,
            locale: $locale,
            variables: $variables,
        );

        return [
            'subject' => $subject,
            'html' => view('emails.layout', ['content' => $content])->render(),
            'text' => view('emails.text', ['content' => $content])->render(),
            'content' => $content,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $content
     * @return array<string, string>
     */
    private function variables(array $entry, array $content): array
    {
        $variables = [];

        foreach ((array) ($entry['sample'] ?? []) as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $variables[(string) $key] = (string) $value;
            }
        }

        $variables['title'] = (string) ($variables['title'] ?? ($content['heading'] ?? ''));
        $variables['body'] = (string) ($variables['body'] ?? ($content['intro'] ?? ''));
        $variables['workspace'] = (string) ($variables['workspace'] ?? ($variables['tenant'] ?? ''));

        return $variables;
    }
}
