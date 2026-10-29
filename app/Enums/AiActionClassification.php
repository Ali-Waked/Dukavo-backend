<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much authority an AI-proposed action carries.
 *
 * Classification is declared by the executor, never by the model or the client.
 * The policy derives approval, confirmation and expiry from it, so a new
 * executor cannot accidentally arrive with weaker governance than its effect
 * deserves.
 */
enum AiActionClassification: string
{
    /** Reads data only. It never changes business state and needs no approval. */
    case ReadOnly = 'read_only';

    /** A recommendation shown to a person. It never changes business state. */
    case Suggestion = 'suggestion';

    /** Creates a record (for example a customer) that a person can still edit. */
    case DraftCreation = 'draft_creation';

    /** Changes existing data in a way that can be corrected by another action. */
    case ReversibleChange = 'reversible_change';

    /** Irreversible, customer-visible or financially significant. */
    case HighRisk = 'high_risk';

    public function label(): string
    {
        return match ($this) {
            self::ReadOnly => 'Read only',
            self::Suggestion => 'Suggestion',
            self::DraftCreation => 'Draft creation',
            self::ReversibleChange => 'Reversible change',
            self::HighRisk => 'High risk',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::ReadOnly => 1,
            self::Suggestion => 2,
            self::DraftCreation => 3,
            self::ReversibleChange => 4,
            self::HighRisk => 5,
        };
    }

    /**
     * Whether this classification is allowed to enter the action lifecycle.
     */
    public function executable(): bool
    {
        return ! in_array($this, [self::ReadOnly, self::Suggestion], true);
    }

    /**
     * Safe defaults. An installation may tighten these in config/ai.php, but it
     * may not remove approval from an executable class.
     *
     * @return array{approval_required: bool, confirmation_required: bool, expires_after_minutes: int}
     */
    public function defaultRequirements(): array
    {
        return match ($this) {
            self::ReadOnly, self::Suggestion => [
                'approval_required' => false,
                'confirmation_required' => false,
                'expires_after_minutes' => 60,
            ],
            self::DraftCreation => [
                'approval_required' => true,
                'confirmation_required' => false,
                'expires_after_minutes' => 1440,
            ],
            self::ReversibleChange => [
                'approval_required' => true,
                'confirmation_required' => true,
                'expires_after_minutes' => 720,
            ],
            self::HighRisk => [
                'approval_required' => true,
                'confirmation_required' => true,
                'expires_after_minutes' => 120,
            ],
        };
    }
}
