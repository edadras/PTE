<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Contracts\UsageCounterStore;
use App\Domain\Commerce\Data\QuotaResult;
use App\Domain\Commerce\Enums\SubscriptionStatus;
use App\Domain\Commerce\Enums\UsageMetric;
use App\Domain\Commerce\Models\Plan;
use App\Domain\Commerce\Models\Subscription;
use App\Domain\Commerce\Models\UsageCounter;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Enforces plan limits.
 *
 * Counters live in Redis (hot path, atomic) and are reconciled into
 * `usage_counters` by RollUpUsageCounters. The rule that shapes this whole
 * class: a quota never throws and never destroys anything — it returns a
 * QuotaResult and the caller degrades the feature (docs/09 §2).
 *
 * @see docs/09-billing-and-plans.md §2
 */
final class QuotaGuard
{
    /** Counters outlive their month so a late roll-up still finds them. */
    private const COUNTER_TTL_SECONDS = 40 * 86400;

    /** @var array<int, Plan|null> */
    private array $planCache = [];

    public function __construct(
        private readonly UsageCounterStore $store = new RedisUsageCounterStore,
        private readonly ?int $academyId = null,
    ) {}

    /** Guard for an academy other than the active tenant (schedulers, roll-ups). */
    public static function forAcademy(int $academyId, ?UsageCounterStore $store = null): self
    {
        return new self($store ?? new RedisUsageCounterStore, $academyId);
    }

    /**
     * Read-only: never mutates a counter.
     */
    public function check(string|UsageMetric $metric, int $amount = 1): QuotaResult
    {
        $metric = UsageMetric::fromMixed($metric);
        $limit = $this->limitFor($metric);

        if ($limit === null) {
            return QuotaResult::unlimited($metric, $this->usage($metric));
        }

        return QuotaResult::evaluate($this->usage($metric), $limit, $metric, $amount);
    }

    /**
     * Reserve $amount of $metric.
     *
     * Increments first and rolls back on overshoot, because the only way two
     * concurrent requests cannot both squeeze past the last unit of quota is
     * for the counter itself to be the arbiter.
     */
    public function consume(string|UsageMetric $metric, int $amount = 1): QuotaResult
    {
        $metric = UsageMetric::fromMixed($metric);
        $limit = $this->limitFor($metric);
        $period = $this->period();
        $academyId = $this->academyId();

        // A gauge is a level, not a tally: setting it is the meaningful
        // operation, so consuming one is really "recount and re-evaluate".
        $after = $this->store->increment($academyId, $metric->value, $period, $amount, self::COUNTER_TTL_SECONDS);

        if ($limit === null) {
            return QuotaResult::unlimited($metric, $after);
        }

        if ($after > $limit) {
            $this->store->decrement($academyId, $metric->value, $period, $amount);

            return QuotaResult::exceeded($after - $amount, $limit, $metric, $amount);
        }

        return QuotaResult::evaluate($after, $limit, $metric);
    }

    /** Give back quota that was reserved but not used (a failed AI call). */
    public function release(string|UsageMetric $metric, int $amount = 1): int
    {
        $metric = UsageMetric::fromMixed($metric);

        return $this->store->decrement($this->academyId(), $metric->value, $this->period(), $amount);
    }

    public function usage(UsageMetric $metric, ?string $period = null): int
    {
        return $this->store->get($this->academyId(), $metric->value, $period ?? $this->period());
    }

    /**
     * Overwrite a gauge (active students, storage used) with a freshly counted
     * value. Flow metrics must never go through here — they only ever add up.
     */
    public function set(string|UsageMetric $metric, int $value, ?string $period = null): void
    {
        $metric = UsageMetric::fromMixed($metric);

        $this->store->put(
            $this->academyId(),
            $metric->value,
            $period ?? $this->period(),
            max(0, $value),
            self::COUNTER_TTL_SECONDS,
        );
    }

    public function remaining(string|UsageMetric $metric): ?int
    {
        return $this->check($metric, 0)->remaining();
    }

    /**
     * Every metric at a glance — what the plan banner and the Super Admin
     * dashboard read.
     *
     * @return array<string, QuotaResult>
     */
    public function snapshot(): array
    {
        $snapshot = [];

        foreach (UsageMetric::cases() as $metric) {
            $snapshot[$metric->value] = $this->check($metric, 0);
        }

        return $snapshot;
    }

    /** Null = unlimited. */
    public function limitFor(string|UsageMetric $metric): ?int
    {
        $metric = UsageMetric::fromMixed($metric);

        return $this->plan()?->limitFor($metric);
    }

    public function period(?CarbonInterface $at = null): string
    {
        return ($at ?? now())->format('Y-m');
    }

    public function academyId(): int
    {
        return $this->academyId ?? TenantContext::id();
    }

    /**
     * Persist the live counter into `usage_counters`.
     *
     * Called by RollUpUsageCounters. Also restores a counter that Redis lost:
     * the durable row is the floor, so a flush cannot hand an academy a free
     * month of unlimited AI.
     */
    public function reconcile(?string $period = null): int
    {
        $period = $period ?? $this->period();
        $academyId = $this->academyId();
        $reconciled = 0;

        foreach (UsageMetric::cases() as $metric) {
            $live = $this->store->get($academyId, $metric->value, $period);

            /** @var UsageCounter|null $row */
            $row = UsageCounter::query()
                ->forAcademy($academyId)
                ->where('period', $period)
                ->where('metric', $metric->value)
                ->first();

            $stored = (int) ($row?->value ?? 0);

            if ($live < $stored) {
                $this->store->put($academyId, $metric->value, $period, $stored, self::COUNTER_TTL_SECONDS);
                $live = $stored;
            }

            if ($live === 0 && $row === null) {
                continue;
            }

            DB::table('usage_counters')->updateOrInsert(
                ['academy_id' => $academyId, 'period' => $period, 'metric' => $metric->value],
                [
                    'value' => $live,
                    'limit_value' => $this->limitFor($metric),
                    'reconciled_at' => now(),
                    'updated_at' => now(),
                    'created_at' => $row?->created_at ?? now(),
                ],
            );

            $reconciled++;
        }

        return $reconciled;
    }

    /**
     * The plan whose limits apply right now.
     *
     * The live subscription wins over `academies.plan_id` because that is what
     * the customer is actually paying for; a suspended subscription falls back
     * to the academy column so a re-activation does not need a second write.
     */
    public function plan(): ?Plan
    {
        $academyId = $this->academyId();

        if (array_key_exists($academyId, $this->planCache)) {
            return $this->planCache[$academyId];
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->forAcademy($academyId)
            ->whereIn('status', [
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->orderByDesc('id')
            ->first();

        $planId = $subscription?->plan_id
            // Read the column directly: Commerce must not depend on the Tenancy
            // model's relations being wired a particular way.
            ?? DB::table('academies')->where('id', $academyId)->value('plan_id');

        return $this->planCache[$academyId] = $planId === null
            ? null
            : Plan::query()->find((int) $planId);
    }

    /** Drop the memoised plan after a plan change inside the same request. */
    public function flush(): void
    {
        $this->planCache = [];
    }
}
