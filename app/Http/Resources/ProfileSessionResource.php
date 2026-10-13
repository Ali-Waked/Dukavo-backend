<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Auth\DeviceName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One signed-in client, backed by a Sanctum personal access token.
 *
 * `name` is the device label written when the token was minted (see
 * {@see DeviceName}); `is_current` lets the security screen
 * keep the caller's own session out of the "revoke" affordance so signing out
 * every other device never signs the user out of the one they are using.
 *
 * @property-read PersonalAccessToken $resource
 */
class ProfileSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_current' => $currentId !== null && $this->id === $currentId,
        ];
    }
}
