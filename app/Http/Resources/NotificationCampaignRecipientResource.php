<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NotificationCampaignRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One merchant's delivery state inside a campaign.
 *
 * The email is masked because an operator diagnosing a failure needs to
 * identify the account, not harvest the mailing list. A skip reason is shown
 * verbatim so "no marketing consent" is distinguishable from a provider error.
 *
 * @mixin NotificationCampaignRecipient
 */
class NotificationCampaignRecipientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'email' => $this->whenLoaded('user', fn () => $this->maskEmail($this->user?->email)),
            'notification_id' => $this->notification_id,
            'status' => $this->status?->value,
            'error' => $this->error,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 2);

        return $visible.str_repeat('•', max(2, mb_strlen($local) - 2)).'@'.$domain;
    }
}
