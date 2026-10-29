<?php

declare(strict_types=1);

namespace App\Enums;

enum VoiceMessageStatus: string
{
    case Queued = 'queued';
    case Transcribing = 'transcribing';
    case Extracting = 'extracting';
    case Ready = 'ready';
    case Applied = 'applied';
    case Failed = 'failed';

    public function isProcessing(): bool
    {
        return in_array($this, [self::Queued, self::Transcribing, self::Extracting], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Applied, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Transcribing => 'Transcribing',
            self::Extracting => 'Preparing order',
            self::Ready => 'Ready for review',
            self::Applied => 'Added to cart',
            self::Failed => 'Needs attention',
        };
    }
}
