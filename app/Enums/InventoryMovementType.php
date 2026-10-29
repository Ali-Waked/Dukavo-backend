<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every inventory mutation is recorded as a movement so the current quantity
 * can always be explained (and audited) by replaying the ledger.
 */
enum InventoryMovementType: string
{
    case Initial = 'initial';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case ReturnIn = 'return_in';
    case ReturnOut = 'return_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Adjustment = 'adjustment';

    /**
     * Sign applied to the inventory quantity when the movement is posted.
     */
    public function direction(): int
    {
        return match ($this) {
            self::Initial, self::Purchase, self::ReturnIn, self::TransferIn => 1,
            self::Sale, self::ReturnOut, self::TransferOut => -1,
            self::Adjustment => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Opening stock',
            self::Purchase => 'Purchase',
            self::Sale => 'Sale',
            self::ReturnIn => 'Return in',
            self::ReturnOut => 'Return out',
            self::TransferIn => 'Transfer in',
            self::TransferOut => 'Transfer out',
            self::Adjustment => 'Adjustment',
        };
    }
}
