<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

use App\Domain\Commerce\Enums\LimitBehaviour;
use App\Domain\Commerce\Enums\QuotaOutcome;
use App\Domain\Commerce\Enums\UsageMetric;

/**
 * The answer to "may I do this, and how close to the edge am I".
 *
 * Deliberately not an exception: a warning is not a failure, and the caller —
 * not the guard — decides how to degrade (docs/09 §2).
 */
final readonly class QuotaResult
{
    private function __construct(
        private QuotaOutcome $outcome,
        private int $used,
        private ?int $limit,
        private ?UsageMetric $metric = null,
        private int $requested = 0,
    ) {}

    public static function unlimited(?UsageMetric $metric = null, int $used = 0): self
    {
        return new self(QuotaOutcome::Unlimited, $used, null, $metric);
    }

    public static function ok(int $used, int $limit, ?UsageMetric $metric = null, int $requested = 0): self
    {
        return new self(QuotaOutcome::Ok, $used, $limit, $metric, $requested);
    }

    public static function warning(int $used, int $limit, ?UsageMetric $metric = null, int $requested = 0): self
    {
        return new self(QuotaOutcome::Warning, $used, $limit, $metric, $requested);
    }

    public static function critical(int $used, int $limit, ?UsageMetric $metric = null, int $requested = 0): self
    {
        return new self(QuotaOutcome::Critical, $used, $limit, $metric, $requested);
    }

    public static function exceeded(int $used, int $limit, ?UsageMetric $metric = null, int $requested = 0): self
    {
        return new self(QuotaOutcome::Exceeded, $used, $limit, $metric, $requested);
    }

    /**
     * Classify a usage level against a limit using the documented thresholds.
     */
    public static function evaluate(int $used, ?int $limit, ?UsageMetric $metric = null, int $requested = 0): self
    {
        if ($limit === null) {
            return self::unlimited($metric, $used);
        }

        // A zero limit means the feature is not part of the plan at all.
        if ($limit <= 0) {
            return self::exceeded($used, $limit, $metric, $requested);
        }

        // Only the allow/deny question looks at what is being asked for; the
        // warning bands describe where the academy already stands, so a single
        // large request cannot make a healthy month read as "critical".
        $ratio = $used / $limit;

        return match (true) {
            $used + $requested > $limit => self::exceeded($used, $limit, $metric, $requested),
            $ratio >= QuotaOutcome::CRITICAL_RATIO => self::critical($used, $limit, $metric, $requested),
            $ratio >= QuotaOutcome::WARNING_RATIO => self::warning($used, $limit, $metric, $requested),
            default => self::ok($used, $limit, $metric, $requested),
        };
    }

    public function allowed(): bool
    {
        return $this->outcome->allowed();
    }

    public function outcome(): QuotaOutcome
    {
        return $this->outcome;
    }

    public function used(): int
    {
        return $this->used;
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    public function metric(): ?UsageMetric
    {
        return $this->metric;
    }

    public function requested(): int
    {
        return $this->requested;
    }

    public function isUnlimited(): bool
    {
        return $this->limit === null;
    }

    public function remaining(): ?int
    {
        if ($this->limit === null) {
            return null;
        }

        return max(0, $this->limit - $this->used);
    }

    /** 0.0–1.0+, or null when there is nothing to be a ratio of. */
    public function ratio(): ?float
    {
        if ($this->limit === null || $this->limit <= 0) {
            return null;
        }

        return $this->used / $this->limit;
    }

    /** What the caller should do instead, when this result is not allowed. */
    public function behaviour(): ?LimitBehaviour
    {
        return $this->metric?->behaviourAtLimit();
    }

    public function message(): string
    {
        if ($this->outcome === QuotaOutcome::Exceeded && $this->metric instanceof UsageMetric) {
            return $this->metric->behaviourAtLimit()->message();
        }

        return __('billing.quota.'.$this->outcome->value, [
            'metric' => $this->metric?->label() ?? '',
            'used' => (string) $this->used,
            'limit' => $this->limit === null ? __('billing.quota.unlimited_value') : (string) $this->limit,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric?->value,
            'outcome' => $this->outcome->value,
            'allowed' => $this->allowed(),
            'used' => $this->used,
            'limit' => $this->limit,
            'remaining' => $this->remaining(),
            'ratio' => $this->ratio(),
            'behaviour' => $this->behaviour()?->value,
        ];
    }
}
