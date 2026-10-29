<?php

declare(strict_types=1);

namespace App\Services\Conversations;

use App\Exceptions\BusinessRuleViolation;
use App\Models\Conversation;
use App\Models\WhatsappAccount;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads, validates and stores inbound WhatsApp media.
 *
 * Meta's media URLs are short-lived and must be fetched with the account token;
 * they must never be put in a conversation payload. Files live on the private
 * disk and are only reachable through the authenticated media controller, which
 * also sends `nosniff` and a server-side content type.
 */
class WhatsAppMediaService
{
    /**
     * MIME types Dukavo is willing to store and re-serve. WhatsApp can deliver
     * more exotic types, but accepting them would turn the inbox into an
     * arbitrary file host for the provider.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'audio/aac' => 'aac',
        'audio/mp4' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/amr' => 'amr',
        'audio/ogg' => 'ogg',
        'video/mp4' => 'mp4',
        'video/3gpp' => '3gp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt',
    ];

    public function __construct(private readonly WhatsAppGateway $gateway) {}

    /**
     * @return array{path: string, mime_type: string, size: int, filename: string}
     */
    public function storeInbound(Conversation $conversation, string $mediaId, ?string $declaredMime = null): array
    {
        $account = $this->accountFor($conversation);

        if ($account === null) {
            throw BusinessRuleViolation::because(
                'The WhatsApp account for this conversation is no longer connected.',
                ['conversation_id' => $conversation->getKey()],
                'whatsapp_not_connected',
            );
        }

        $media = $this->gateway->downloadMedia($account, $mediaId);
        $mimeType = strtolower(trim(explode(';', $media['mime_type'] !== '' ? $media['mime_type'] : (string) $declaredMime)[0]));

        if (! isset(self::TYPES[$mimeType])) {
            throw BusinessRuleViolation::because(
                'This attachment type is not supported.',
                ['mime_type' => $mimeType],
                'unsupported_media_type',
            );
        }

        $maxBytes = max(1, (int) config('services.whatsapp.media_max_bytes', 25 * 1024 * 1024));

        if ($media['size'] > $maxBytes) {
            throw BusinessRuleViolation::because(
                'This attachment is too large.',
                ['size' => $media['size'], 'max_bytes' => $maxBytes],
                'media_too_large',
            );
        }

        $path = sprintf(
            'whatsapp/%d/%d/%s.%s',
            $conversation->tenant_id,
            $conversation->getKey(),
            Str::uuid()->toString(),
            self::TYPES[$mimeType],
        );

        if (! Storage::disk('local')->put($path, $media['contents'])) {
            throw BusinessRuleViolation::because(
                'The attachment could not be saved.',
                ['conversation_id' => $conversation->getKey()],
                'media_storage_failed',
            );
        }

        return [
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => $media['size'],
            'filename' => $this->safeFilename($media['filename'] ?? null, self::TYPES[$mimeType]),
        ];
    }

    /**
     * Remove a file that belongs to a message being deleted. Missing files are
     * ignored: cleanup must not block removing a message row.
     */
    public function forget(?string $path): void
    {
        if (filled($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    private function accountFor(Conversation $conversation): ?WhatsappAccount
    {
        $account = $conversation->whatsapp_account_id !== null
            ? WhatsappAccount::query()->find($conversation->whatsapp_account_id)
            : null;

        if ($account instanceof WhatsappAccount && $account->isUsable()) {
            return $account;
        }

        return WhatsappAccount::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('status', 'connected')
            ->when($conversation->store_id !== null, fn ($query) => $query->where(function ($scoped) use ($conversation): void {
                $scoped->where('store_id', $conversation->store_id)->orWhereNull('store_id');
            }))
            ->orderByDesc('is_active')
            ->first();
    }

    private function safeFilename(?string $original, string $extension): string
    {
        $name = $original !== null ? pathinfo($original, PATHINFO_FILENAME) : '';
        $name = Str::ascii($name);
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-._');

        if ($name === '') {
            $name = 'whatsapp-attachment';
        }

        return Str::limit($name, 80, '').'.'.$extension;
    }
}
