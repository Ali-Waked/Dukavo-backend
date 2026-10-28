<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AiActionClassification;
use App\Models\AiAction;
use App\Services\Ai\Governance\AiActionPolicy;
use App\Services\Ai\Governance\Executors\AiActionExecutorRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * @property-read AiAction $resource
 */
class AiActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $classification = $this->classification;
        $requiredPermission = null;

        try {
            $executor = app(AiActionExecutorRegistry::class)->get($this->action_type);
            $classification ??= $executor->classification();
            $requiredPermission = $executor->requiredPermission();
        } catch (Throwable) {
            // An executor removed between proposal and review must still render
            // the stored action, without pretending it can be executed.
        }

        $requirements = $classification instanceof AiActionClassification
            ? app(AiActionPolicy::class)->requirementsFor($classification)
            : null;

        return [
            'id' => $this->id,
            'use_case' => $this->use_case,
            'action_type' => $this->action_type,
            'classification' => $classification?->value,
            'classification_label' => $classification?->label(),
            'required_permission' => $requiredPermission,
            'requires_confirmation' => (bool) ($requirements['confirmation_required'] ?? true),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'summary' => $this->summary,
            'resolved_payload' => $this->resolved_payload,
            'result' => $this->result,
            'rejection_reason' => $this->rejection_reason,
            'error' => $this->error,
            'can_execute' => $this->isExecutable(),
            'is_expired' => $this->hasExpired(),
            'proposer' => $this->whenLoaded('proposer', fn () => $this->proposer ? [
                'id' => $this->proposer->id,
                'name' => $this->proposer->name,
            ] : null),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'executor' => $this->whenLoaded('executor', fn () => $this->executor ? [
                'id' => $this->executor->id,
                'name' => $this->executor->name,
            ] : null),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
