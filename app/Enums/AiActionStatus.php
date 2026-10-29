<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of an AI-proposed business action.
 *
 * The states exist to keep two things apart that are easy to conflate: what
 * the model *suggested* and what the business actually *did*. A suggestion
 * starts as a draft, is submitted, is judged by a person, and only then may be
 * executed — exactly once. An approval that is not used before its expiry dies;
 * a stale decision must never be applied to a changed world.
 */
enum AiActionStatus: string
{
    /** Proposed by the assistant, not yet offered to anyone. */
    case Draft = 'draft';

    /** Waiting for a person with the right permission to decide. */
    case PendingApproval = 'pending_approval';

    /** A person authorised it. It may now be executed, once. */
    case Approved = 'approved';

    /** A person refused it. It will not run. */
    case Rejected = 'rejected';

    /** It ran. The business effect is recorded. */
    case Executed = 'executed';

    /** It was approved but the execution failed. It must be reviewed again. */
    case Failed = 'failed';

    /** The approval window passed before anyone executed it. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Executed => 'Executed',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    /**
     * The states an action may move to from here.
     *
     * The machine is deliberately narrow: nothing skips approval, and nothing
     * leaves `executed`.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Rejected, self::Expired],
            self::PendingApproval => [self::Approved, self::Rejected, self::Expired],
            self::Approved => [self::Executed, self::Failed, self::Expired],
            self::Failed => [self::Approved, self::Rejected, self::Expired],
            self::Rejected, self::Executed, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Whether the action has reached a final state.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
