<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * Everything the platform meters per academy per calendar month.
 *
 * @see docs/09-billing-and-plans.md §2
 */
enum UsageMetric: string
{
    case AiRequests = 'ai_requests';
    case AiTokens = 'ai_tokens';
    case AiCostUsd = 'ai_cost_usd';
    case AsrMinutes = 'asr_minutes';
    case StorageMb = 'storage_mb';
    case ActiveStudents = 'active_students';
    case StaffUsers = 'staff_users';
    case Broadcasts = 'broadcasts';
    case Questions = 'questions';

    public function label(): string
    {
        return __('billing.metric.'.$this->value);
    }

    /**
     * The documented degradation for this metric (docs/09 §2).
     */
    public function behaviourAtLimit(): LimitBehaviour
    {
        return match ($this) {
            // AI scoring stops; deterministic (algorithmic) practice keeps running.
            self::AiRequests, self::AiTokens, self::AiCostUsd => LimitBehaviour::DegradeAiScoring,
            self::AsrMinutes => LimitBehaviour::DisableSpeaking,
            self::StorageMb => LimitBehaviour::BlockUploads,
            self::ActiveStudents => LimitBehaviour::BlockNewEnrollments,
            self::StaffUsers => LimitBehaviour::BlockStaffInvites,
            self::Broadcasts => LimitBehaviour::BlockBroadcasts,
            self::Questions => LimitBehaviour::BlockQuestionCreation,
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::AiRequests, self::ActiveStudents, self::StaffUsers,
            self::Broadcasts, self::Questions => 'count',
            self::AiTokens => 'tokens',
            self::AiCostUsd => 'usd_cents',
            self::AsrMinutes => 'minutes',
            self::StorageMb => 'megabytes',
        };
    }

    /**
     * Gauges hold a level, not a running total: re-counting them each period
     * would be wrong, so RollUpUsageCounters recomputes rather than accumulates.
     */
    public function isGauge(): bool
    {
        return in_array($this, [
            self::ActiveStudents,
            self::StaffUsers,
            self::StorageMb,
            self::Questions,
        ], true);
    }

    /** Gauges carry over between periods; flow metrics reset every month. */
    public function resetsEachPeriod(): bool
    {
        return ! $this->isGauge();
    }

    public static function tryFromMixed(string|self $metric): ?self
    {
        return $metric instanceof self ? $metric : self::tryFrom($metric);
    }

    public static function fromMixed(string|self $metric): self
    {
        return $metric instanceof self ? $metric : self::from($metric);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
