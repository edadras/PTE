<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * What actually happens when a metric hits its ceiling.
 *
 * The governing rule from docs/09 §2: a limit must never destroy existing data
 * and must never take the whole service down. Every case below therefore
 * disables one forward-looking capability and leaves everything already bought
 * or already produced intact.
 */
enum LimitBehaviour: string
{
    case BlockNewEnrollments = 'block_new_enrollments';
    case DegradeAiScoring = 'degrade_ai_scoring';
    case DisableSpeaking = 'disable_speaking';
    case BlockUploads = 'block_uploads';
    case BlockStaffInvites = 'block_staff_invites';
    case BlockBroadcasts = 'block_broadcasts';
    case BlockQuestionCreation = 'block_question_creation';

    public function label(): string
    {
        return __('billing.limit_behaviour.'.$this->value);
    }

    /** The message a student or admin sees instead of the blocked feature. */
    public function message(): string
    {
        return __('billing.limit_message.'.$this->value);
    }

    /**
     * True when the degraded path still lets the user practise, just without
     * the metered part. This is the difference between "annoying" and "broken".
     */
    public function leavesFallbackPath(): bool
    {
        return match ($this) {
            self::DegradeAiScoring => true,
            default => false,
        };
    }

    /** Invariant, asserted in tests: no limit ever deletes anything. */
    public function preservesExistingData(): bool
    {
        return true;
    }

    /** Invariant: hitting a quota never silences the bot. Only suspension does. */
    public function stopsBotEntirely(): bool
    {
        return false;
    }
}
