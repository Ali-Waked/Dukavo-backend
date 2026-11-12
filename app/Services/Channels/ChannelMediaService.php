<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Exceptions\BusinessRuleViolation;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Services\Channels\Data\NormalizedInboundMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads and stores inbound attachments for any channel.
 *
 * The connector fetches the bytes; this service applies the same policy to
 * every provider: a closed MIME allow-list, a size ceiling, a private disk and
 * a server-generated filename. A merchant's inbox must never become an
 * arbitrary file host for a provider.
 */
final class ChannelMediaService
{
    /**
     * MIME types Dukavo is willing to store and re-serve.
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
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
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

    public function __construct(private readonly ChannelProviderRegistry $connectors) {}

    /**
     * @return array{path: string, mime_type: string, size: int, filename: string}
     */
    public function storeInbound(
        ChannelConnection $connection,
        Conversation $conversation,
        NormalizedInboundMessage $message,
    ): array {
        if ($message->mediaId === null) {
            throw BusinessRuleViolation::because(
                'The message has no attachment.',
                ['conversation_id' => $conversation->getKey()],
                'media_missing',
            );
        }

        $connector = $this->connectors->for($connection->channel);

        if (! $connector->capabilities()->mediaDownload) {
            throw BusinessRuleViolation::because(
                'This channel cannot download attachments.',
                ['channel' => $connection->channel],
                'media_download_unsupported',
            );
        }

        $media = $connector->downloadMedia($connection, $message->mediaId);
        $mimeType = strtolower(trim(explode(';', $media->mimeType !== '' ? $media->mimeType : (string) $message->mediaMimeType)[0]));

        if (! isset(self::TYPES[$mimeType])) {
            throw BusinessRuleViolation::because(
                'This attachment type is not supported.',
                ['mime_type' => $mimeType],
                'unsupported_media_type',
            );
        }

        $maxBytes = max(1, (int) config('services.channels.media_max_bytes', 25 * 1024 * 1024));

        if ($media->size > $maxBytes) {
            throw BusinessRuleViolation::because(
                'This attachment is too large.',
                ['size' => $media->size, 'max_bytes' => $maxBytes],
                'media_too_large',
            );
        }

        $path = sprintf(
            'channels/%s/%d/%d/%s.%s',
            $connection->channel,
            $conversation->tenant_id,
            $conversation->getKey(),
            Str::uuid()->toString(),
            self::TYPES[$mimeType],
        );

        if (! Storage::disk('local')->put($path, $media->contents)) {
            throw BusinessRuleViolation::because(
                'The attachment could not be saved.',
                ['conversation_id' => $conversation->getKey()],
                'media_storage_failed',
            );
        }

        return [
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => $media->size,
            'filename' => $this->safeFilename($media->filename ?? $message->mediaFilename, self::TYPES[$mimeType]),
        ];
    }

    /**
     * Remove a file that belongs to a message being deleted. Missing files are
     * ignored: cleanup must not block removing a message row.
     */
    public function forget(?string $path): void
    {
        if (filled($path)) {
            try {
                Storage::disk('local')->delete($path);
            } catch (Throwable) {
                // Storage being unavailable must not stop a deletion.
            }
        }
    }

    private function safeFilename(?string $original, string $extension): string
    {
        $name = $original !== null ? pathinfo($original, PATHINFO_FILENAME) : '';
        $name = Str::ascii($name);
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-._');

        if ($name === '') {
            $name = 'channel-attachment';
        }

        return Str::limit($name, 80, '').'.'.$extension;
    }
}
