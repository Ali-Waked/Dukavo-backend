<?php

declare(strict_types=1);

namespace App\Enums;

enum CartStatus: string
{
    case Draft = 'draft';
    case PendingConfirmation = 'pending_confirmation';
    case Converted = 'converted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingConfirmation => 'Awaiting confirmation',
            self::Converted => 'Converted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Converted, self::Cancelled], true);
    }

    /**
     * A cart is editable only while it is a draft. Once the customer has been
     * asked to confirm, the proposal they are looking at must not move beneath
     * them; the merchant reopens it deliberately instead.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingConfirmation, self::Cancelled],
            self::PendingConfirmation => [self::Draft, self::Converted, self::Cancelled],
            self::Converted, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
