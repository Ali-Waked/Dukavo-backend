<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Translation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Translations\ApproveTranslationsRequest;
use App\Http\Requests\Translations\GenerateTranslationsRequest;
use App\Http\Requests\Translations\RegenerateTranslationRequest;
use App\Http\Requests\Translations\RejectTranslationRequest;
use App\Http\Requests\Translations\SaveTranslationsRequest;
use App\Http\Requests\Translations\UpdateTranslationRequest;
use App\Models\Translation;
use App\Services\Translation\TranslationEntity;
use App\Services\Translation\TranslationEntityRegistry;
use App\Services\Translation\TranslationField;
use App\Services\Translation\TranslationService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The AI translation review surface.
 *
 * One generic controller serves every translatable entity because the entity
 * registry already knows which model, fields and storage each one uses. Every
 * route resolves the record inside the current workspace (or the platform
 * surface), so an id from another tenant is a 404 rather than a leak.
 *
 * The controller never publishes anything itself: generation produces a review
 * proposal and only the explicit approve action makes copy public.
 */
final class TranslationController extends Controller
{
    public function __construct(
        private readonly TranslationService $translations,
        private readonly TranslationEntityRegistry $registry,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * The catalogue of translatable entity types and the platform locales.
     */
    public function entities(): JsonResponse
    {
        return ApiResponse::success('', [
            'entities' => array_map(
                fn (TranslationEntity $entity): array => [
                    'key' => $entity->key,
                    'label' => $entity->label,
                    'tenant_scoped' => $entity->tenantScoped,
                    'fields' => array_map(
                        static fn (TranslationField $field): array => [
                            'key' => $field->key,
                            'label' => $field->label,
                            'format' => $field->format,
                        ],
                        $entity->fields,
                    ),
                ],
                $this->registry->all(),
            ),
            'locales' => array_values((array) config('dukavo.locales.supported', ['en'])),
        ]);
    }

    /**
     * The review payload for one record: sources, enabled targets and every
     * translation row with its status.
     */
    public function show(Request $request, string $entity, int $id): JsonResponse
    {
        ['entity' => $definition, 'model' => $model] = $this->resolved($entity, $id);

        $fields = $request->filled('fields')
            ? array_values(array_filter((array) $request->query('fields'), static fn (mixed $field): bool => is_string($field)))
            : null;

        $source = $request->filled('source_locale') ? $request->string('source_locale')->toString() : null;

        return ApiResponse::success('', $this->translations->overview(
            $definition,
            $model,
            $source,
            $this->tenants->tenant(),
            $fields,
        ));
    }

    /**
     * Generate proposals for one or more target languages. Nothing is public
     * until a reviewer approves it.
     */
    public function generate(GenerateTranslationsRequest $request, string $entity, int $id): JsonResponse
    {
        ['entity' => $definition, 'model' => $model] = $this->resolved($entity, $id);

        $payload = $this->translations->generate(
            $definition,
            $model,
            array_values($request->validated('fields')),
            array_values($request->validated('target_locales') ?? []),
            $request->validated('source_locale'),
            $request->user()?->getKey(),
            (bool) ($request->validated('confirm_overwrite') ?? false),
            array_values($request->validated('protected_terms') ?? []),
        );

        return ApiResponse::success('Translations generated for review.', $payload);
    }

    /**
     * Today's translation usage, so the merchant sees the cost of the assistant.
     */
    public function usage(Request $request): JsonResponse
    {
        return ApiResponse::success('', $this->translations->usageToday(
            $this->tenants->tenant(),
            $request->boolean('mine') ? $request->user()?->getKey() : null,
        ));
    }

    public function update(UpdateTranslationRequest $request, Translation $translation): JsonResponse
    {
        $this->guard($translation);

        $updated = $this->translations->update(
            $translation,
            (string) $request->validated('translated_text'),
            (int) $request->user()?->getKey(),
        );

        return ApiResponse::success('Translation updated and returned to review.', $this->translations->present($updated));
    }

    /**
     * Save the inline language-tab editor for one record.
     *
     * A merchant typed this copy and pressed save, so it is approved and
     * mirrored immediately. The AI path never reaches this method.
     */
    public function save(SaveTranslationsRequest $request, string $entity, int $id): JsonResponse
    {
        ['entity' => $definition, 'model' => $model] = $this->resolved($entity, $id);

        $saved = $this->translations->saveManual(
            $definition,
            $model,
            $request->validated('translations'),
            (int) $request->user()?->getKey(),
            $this->tenants->tenant(),
        );

        return ApiResponse::success('Translations saved.', ['translations' => $saved]);
    }

    public function approve(Request $request, Translation $translation): JsonResponse
    {
        $this->guard($translation);

        $approved = $this->translations->approve(
            $translation,
            (int) $request->user()?->getKey(),
            $this->tenants->tenant(),
        );

        return ApiResponse::success('Translation approved and published.', $this->translations->present($approved));
    }

    public function reject(RejectTranslationRequest $request, Translation $translation): JsonResponse
    {
        $this->guard($translation);

        $rejected = $this->translations->reject(
            $translation,
            $request->validated('reason'),
            (int) $request->user()?->getKey(),
        );

        return ApiResponse::success('Translation rejected.', $this->translations->present($rejected));
    }

    public function regenerate(RegenerateTranslationRequest $request, Translation $translation): JsonResponse
    {
        $this->guard($translation);

        $payload = $this->translations->regenerate(
            $translation,
            (bool) ($request->validated('confirm_overwrite') ?? false),
            $request->user()?->getKey(),
        );

        return ApiResponse::success('Translation regenerated for review.', $payload);
    }

    /**
     * Approve exactly the rows the merchant selected. The ids must all belong
     * to the record in the URL; a partial match is refused rather than silently
     * approving the subset.
     */
    public function approveAll(ApproveTranslationsRequest $request, string $entity, int $id): JsonResponse
    {
        ['entity' => $definition, 'model' => $model] = $this->resolved($entity, $id);

        $approved = $this->translations->approveMany(
            array_values($request->validated('ids')),
            $definition,
            $model,
            (int) $request->user()?->getKey(),
            $this->tenants->tenant(),
        );

        return ApiResponse::success('Selected translations approved.', [
            'approved' => array_map(fn (Translation $row): array => $this->translations->present($row), $approved),
        ]);
    }

    /**
     * @return array{entity: TranslationEntity, model: Model}
     */
    private function resolved(string $entity, int $id): array
    {
        return $this->translations->resolve($entity, $id, $this->tenants->tenant());
    }

    /**
     * A translation row is only reachable when its entity type and workspace
     * match the current surface, and the underlying record still exists inside
     * that workspace.
     */
    private function guard(Translation $translation): void
    {
        $tenant = $this->tenants->tenant();
        $entity = $this->registry->get((string) $translation->translatable_type);

        abort_if($entity === null, 404);
        abort_if($entity->tenantScoped !== ($tenant !== null), 404);

        if ($entity->tenantScoped && (int) $translation->tenant_id !== (int) $tenant?->getKey()) {
            abort(404);
        }

        if (! $entity->tenantScoped && $translation->tenant_id !== null) {
            abort(404);
        }

        // Verify the record itself is still inside this workspace, not merely
        // that the row claims a matching tenant id.
        $this->translations->resolve($entity->key, (int) $translation->translatable_id, $tenant);
    }
}
