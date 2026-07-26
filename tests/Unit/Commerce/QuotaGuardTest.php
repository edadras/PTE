<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Domain\Commerce\Enums\LimitBehaviour;
use App\Domain\Commerce\Enums\QuotaOutcome;
use App\Domain\Commerce\Enums\UsageMetric;
use App\Domain\Commerce\Models\Plan;
use App\Domain\Commerce\Models\UsageCounter;
use App\Domain\Commerce\Services\CacheUsageCounterStore;
use App\Domain\Commerce\Services\QuotaGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class QuotaGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $academyId;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::query()->create([
            'key' => 'test-plan',
            'name' => 'Test',
            'price_monthly' => 2_500_000,
            'price_yearly' => 25_000_000,
            'currency' => 'IRR',
            'limits' => [
                'ai_requests' => 1_000,
                'asr_minutes' => 300,
                'ai_tokens' => null,
            ],
            'features' => [],
        ]);

        $this->academyId = (int) DB::table('academies')->insertGetId([
            'slug' => 'quota-test',
            'name' => 'Quota Test',
            'status' => 'active',
            'plan_id' => $plan->getKey(),
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function guard(): QuotaGuard
    {
        return QuotaGuard::forAcademy($this->academyId, new CacheUsageCounterStore);
    }

    /**
     * @return array<string, array{0: int, 1: QuotaOutcome, 2: bool}>
     */
    public static function thresholds(): array
    {
        return [
            'fresh month' => [0, QuotaOutcome::Ok, true],
            'just below warning' => [799, QuotaOutcome::Ok, true],
            'at 80 percent' => [800, QuotaOutcome::Warning, true],
            'between warning and critical' => [940, QuotaOutcome::Warning, true],
            'at 95 percent' => [950, QuotaOutcome::Critical, true],
            'one below the limit' => [999, QuotaOutcome::Critical, true],
            'exactly at the limit' => [1000, QuotaOutcome::Exceeded, false],
            'over the limit' => [1200, QuotaOutcome::Exceeded, false],
        ];
    }

    #[Test]
    #[DataProvider('thresholds')]
    public function it_classifies_usage_against_the_documented_thresholds(int $used, QuotaOutcome $expected, bool $allowed): void
    {
        $guard = $this->guard();
        $guard->set(UsageMetric::AiRequests, $used);

        $result = $guard->check(UsageMetric::AiRequests);

        $this->assertSame($expected, $result->outcome());
        $this->assertSame($allowed, $result->allowed());
        $this->assertSame($used, $result->used());
        $this->assertSame(1_000, $result->limit());
    }

    #[Test]
    public function check_never_mutates_the_counter(): void
    {
        $guard = $this->guard();
        $guard->set(UsageMetric::AiRequests, 10);

        $guard->check(UsageMetric::AiRequests, 50);
        $guard->check(UsageMetric::AiRequests, 50);

        $this->assertSame(10, $guard->usage(UsageMetric::AiRequests));
    }

    #[Test]
    public function consume_increments_atomically(): void
    {
        $guard = $this->guard();

        $guard->consume(UsageMetric::AiRequests, 3);
        $result = $guard->consume(UsageMetric::AiRequests, 2);

        $this->assertSame(5, $guard->usage(UsageMetric::AiRequests));
        $this->assertSame(QuotaOutcome::Ok, $result->outcome());
    }

    #[Test]
    public function consume_rolls_back_when_the_request_would_overshoot(): void
    {
        $guard = $this->guard();
        $guard->set(UsageMetric::AiRequests, 999);

        $result = $guard->consume(UsageMetric::AiRequests, 5);

        $this->assertFalse($result->allowed());
        $this->assertSame(QuotaOutcome::Exceeded, $result->outcome());
        // The rejected request must not have eaten quota it was denied.
        $this->assertSame(999, $guard->usage(UsageMetric::AiRequests));
    }

    #[Test]
    public function consume_allows_the_request_that_lands_exactly_on_the_limit(): void
    {
        $guard = $this->guard();
        $guard->set(UsageMetric::AiRequests, 999);

        $result = $guard->consume(UsageMetric::AiRequests);

        $this->assertTrue($result->allowed());
        $this->assertSame(1_000, $guard->usage(UsageMetric::AiRequests));
        $this->assertSame(QuotaOutcome::Critical, $result->outcome());
    }

    #[Test]
    public function a_null_limit_is_unlimited_and_always_allowed(): void
    {
        $guard = $this->guard();

        $result = $guard->consume(UsageMetric::AiTokens, 10_000_000);

        $this->assertTrue($result->allowed());
        $this->assertSame(QuotaOutcome::Unlimited, $result->outcome());
        $this->assertNull($result->limit());
        $this->assertNull($result->remaining());
        $this->assertNull($result->ratio());
    }

    #[Test]
    public function a_metric_missing_from_the_plan_is_unlimited(): void
    {
        $result = $this->guard()->check(UsageMetric::Broadcasts);

        $this->assertSame(QuotaOutcome::Unlimited, $result->outcome());
        $this->assertTrue($result->allowed());
    }

    #[Test]
    public function remaining_and_ratio_report_the_headroom(): void
    {
        $guard = $this->guard();
        $guard->set(UsageMetric::AsrMinutes, 240);

        $result = $guard->check(UsageMetric::AsrMinutes);

        $this->assertSame(60, $result->remaining());
        $this->assertEqualsWithDelta(0.8, $result->ratio(), 0.0001);
    }

    #[Test]
    public function exceeding_a_metric_degrades_the_feature_instead_of_stopping_the_service(): void
    {
        $guard = $this->guard();
        $guard->set(UsageMetric::AiRequests, 1_000);

        $result = $guard->check(UsageMetric::AiRequests);
        $behaviour = $result->behaviour();

        $this->assertSame(LimitBehaviour::DegradeAiScoring, $behaviour);
        // docs/09 §2: AI scoring stops, deterministic practice keeps running.
        $this->assertTrue($behaviour?->leavesFallbackPath());
        $this->assertFalse($behaviour?->stopsBotEntirely());
        $this->assertTrue($behaviour?->preservesExistingData());
        $this->assertNotSame('', $result->message());
    }

    #[Test]
    public function every_metric_degrades_without_destroying_data_or_silencing_the_bot(): void
    {
        foreach (UsageMetric::cases() as $metric) {
            $behaviour = $metric->behaviourAtLimit();

            $this->assertTrue($behaviour->preservesExistingData(), $metric->value);
            $this->assertFalse($behaviour->stopsBotEntirely(), $metric->value);
        }
    }

    #[Test]
    public function release_hands_back_reserved_quota(): void
    {
        $guard = $this->guard();
        $guard->consume(UsageMetric::AiRequests, 10);

        $guard->release(UsageMetric::AiRequests, 4);

        $this->assertSame(6, $guard->usage(UsageMetric::AiRequests));
    }

    #[Test]
    public function reconcile_writes_the_live_counter_into_the_usage_table(): void
    {
        $guard = $this->guard();
        $guard->consume(UsageMetric::AiRequests, 42);

        $guard->reconcile();

        $counter = UsageCounter::query()
            ->forAcademy($this->academyId)
            ->where('metric', UsageMetric::AiRequests->value)
            ->first();

        $this->assertNotNull($counter);
        $this->assertSame(42, $counter->value);
        $this->assertSame(1_000, $counter->limit_value);
    }

    #[Test]
    public function reconcile_restores_a_counter_that_the_hot_store_lost(): void
    {
        $store = new CacheUsageCounterStore;
        $guard = QuotaGuard::forAcademy($this->academyId, $store);
        $period = $guard->period();

        $guard->consume(UsageMetric::AiRequests, 500);
        $guard->reconcile();

        // Simulate a Redis flush: the durable row must become the floor again,
        // otherwise a restart would hand the academy a free month.
        $store->forget($this->academyId, UsageMetric::AiRequests->value, $period);
        $this->assertSame(0, $guard->usage(UsageMetric::AiRequests));

        $guard->reconcile();

        $this->assertSame(500, $guard->usage(UsageMetric::AiRequests));
    }

    #[Test]
    public function usage_is_scoped_per_academy(): void
    {
        $otherAcademyId = (int) DB::table('academies')->insertGetId([
            'slug' => 'quota-test-other',
            'name' => 'Other',
            'status' => 'active',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->guard()->consume(UsageMetric::AiRequests, 25);

        $other = QuotaGuard::forAcademy($otherAcademyId, new CacheUsageCounterStore);

        $this->assertSame(0, $other->usage(UsageMetric::AiRequests));
    }
}
