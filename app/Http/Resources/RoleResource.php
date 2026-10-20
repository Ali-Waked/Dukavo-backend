<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Role $resource
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'scope' => $this->scope->value,
            'tenant_id' => $this->tenant_id,
            'scope_label' => $this->scope->label(),
            'description' => $this->description,
            'is_system' => $this->is_system,
            'requires_tenant' => $this->scope->requiresTenant(),
            'permissions' => PermissionResource::collection(
                $this->whenLoaded('permissions', fn () => $this->permissions)
            ),
            'permission_slugs' => $this->when(
                $this->relationLoaded('permissions'),
                fn (): array => $this->permissions->pluck('slug')->all(),
            ),
        ];
    }
}
