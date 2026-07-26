<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Notification\Enums\ScheduledContentStatus;
use App\Domain\Notification\Enums\ScheduledContentType;
use App\Domain\Notification\Jobs\DispatchScheduledContents;
use App\Domain\Notification\Jobs\SendDailyPracticeNudge;
use App\Domain\Notification\Jobs\SendReEngagementCampaign;
use App\Domain\Notification\Models\ScheduledContent;
use App\Domain\Notification\Support\RepeatRule;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The part of scheduling that is usually wrong: a wall-clock time an academy
 * chose must fire at that time *where the academy is*, and a daily rule must
 * keep firing at that local time forever.
 *
 * @see docs/05-modules-exams-practice.md §6
 */
final class ScheduledContentTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Academy $tehran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tehran = Academy::factory()->configured()->create([
            'slug' => 'tehran',
            'timezone' => 'Asia/Tehran',
        ]);
    }

    #[Test]
    public function a_nine_am_local_schedule_is_stored_as_the_matching_utc_instant(): void
    {
        $content = TenantContext::runFor($this->tehran, fn (): ScheduledContent => ScheduledContent::scheduleLocal(
            $this->tehran,
            ScheduledContentType::DailyPractice,
            '2026-07-21 09:00:00',
            'daily',
        ));

        // 09:00 in Tehran (UTC+03:30) is 05:30 UTC — not 09:00 UTC.
        $this->assertSame('2026-07-21 05:30:00', $content->scheduled_at->utc()->toDateTimeString());
        $this->assertSame('Asia/Tehran', $content->timezone);
        $this->assertSame('09:00', $content->localScheduledAt()->format('H:i'));
    }

    #[Test]
    public function it_does_not_fire_at_nine_am_utc_and_does_fire_at_nine_am_local(): void
    {
        Bus::fake([SendDailyPracticeNudge::class]);

        $this->scheduleDailyNudgeAt('2026-07-21 09:00:00');

        // 04:00 UTC — before the academy's 09:00, which is 05:30 UTC.
        $this->travelTo(Carbon::parse('2026-07-21 04:00:00', 'UTC'));
        $this->assertSame(0, (new DispatchScheduledContents)->handle());
        Bus::assertNothingDispatched();

        // 05:30 UTC — the academy's 09:00.
        $this->travelTo(Carbon::parse('2026-07-21 05:30:00', 'UTC'));
        $this->assertSame(1, (new DispatchScheduledContents)->handle());

        Bus::assertDispatched(
            SendDailyPracticeNudge::class,
            fn (SendDailyPracticeNudge $job): bool => $job->academyId === (int) $this->tehran->getKey()
        );
    }

    #[Test]
    public function a_daily_rule_keeps_the_local_wall_clock_time_after_it_fires(): void
    {
        Bus::fake([SendDailyPracticeNudge::class]);

        $content = $this->scheduleDailyNudgeAt('2026-07-21 09:00:00');

        $this->travelTo(Carbon::parse('2026-07-21 05:30:00', 'UTC'));
        (new DispatchScheduledContents)->handle();

        $content->refresh();

        $this->assertSame(ScheduledContentStatus::Pending, $content->status);
        $this->assertSame(1, $content->run_count);
        $this->assertSame('09:00', $content->localScheduledAt()->format('H:i'));
        $this->assertSame('2026-07-22', $content->localScheduledAt()->toDateString());
    }

    #[Test]
    public function a_one_shot_schedule_is_finished_rather_than_rescheduled(): void
    {
        Bus::fake([SendDailyPracticeNudge::class]);

        $content = $this->scheduleDailyNudgeAt('2026-07-21 09:00:00', repeat: null);

        $this->travelTo(Carbon::parse('2026-07-21 05:30:00', 'UTC'));
        (new DispatchScheduledContents)->handle();

        $content->refresh();

        $this->assertSame(ScheduledContentStatus::Done, $content->status);
        $this->assertNotNull($content->executed_at);
    }

    #[Test]
    public function a_scheduler_outage_does_not_replay_the_backlog(): void
    {
        Bus::fake([SendDailyPracticeNudge::class]);

        $content = $this->scheduleDailyNudgeAt('2026-07-21 09:00:00');

        // Three days late.
        $this->travelTo(Carbon::parse('2026-07-24 06:00:00', 'UTC'));
        $this->assertSame(1, (new DispatchScheduledContents)->handle());

        $content->refresh();

        $this->assertSame(1, $content->run_count);
        $this->assertTrue($content->scheduled_at->isFuture(), 'The next run must be ahead of now, not behind it.');
        $this->assertSame('09:00', $content->localScheduledAt()->format('H:i'));
    }

    #[Test]
    public function two_academies_in_different_zones_fire_at_their_own_nine_am(): void
    {
        Bus::fake([SendDailyPracticeNudge::class]);

        $london = Academy::factory()->configured()->create(['slug' => 'london', 'timezone' => 'Europe/London']);

        $this->scheduleDailyNudgeAt('2026-07-21 09:00:00');

        TenantContext::runFor($london, fn () => ScheduledContent::scheduleLocal(
            $london,
            ScheduledContentType::DailyPractice,
            '2026-07-21 09:00:00',
            'daily',
        ));

        // 05:30 UTC: 09:00 in Tehran, 06:30 in London (BST).
        $this->travelTo(Carbon::parse('2026-07-21 05:30:00', 'UTC'));
        (new DispatchScheduledContents)->handle();

        Bus::assertDispatchedTimes(SendDailyPracticeNudge::class, 1);

        // 08:00 UTC is 09:00 in London.
        $this->travelTo(Carbon::parse('2026-07-21 08:00:00', 'UTC'));
        (new DispatchScheduledContents)->handle();

        Bus::assertDispatchedTimes(SendDailyPracticeNudge::class, 2);
    }

    #[Test]
    public function the_repeat_rule_advances_in_local_time(): void
    {
        $start = Carbon::parse('2026-07-21 05:30:00', 'UTC');

        $next = RepeatRule::next('daily', $start, 'Asia/Tehran');
        $this->assertSame('2026-07-22 05:30:00', $next?->toDateTimeString());
        $this->assertSame('09:00', $next?->copy()->setTimezone('Asia/Tehran')->format('H:i'));

        $weekly = RepeatRule::next('weekly', $start, 'Asia/Tehran');
        $this->assertSame('2026-07-28 05:30:00', $weekly?->toDateTimeString());

        $this->assertNull(RepeatRule::next(null, $start, 'Asia/Tehran'));
        $this->assertNull(RepeatRule::next('nonsense', $start, 'Asia/Tehran'));

        $every = RepeatRule::next('every:30m', $start, 'Asia/Tehran');
        $this->assertSame('2026-07-21 06:00:00', $every?->toDateTimeString());
    }

    #[Test]
    public function a_due_row_is_claimed_so_overlapping_sweeps_cannot_both_run_it(): void
    {
        Bus::fake([SendDailyPracticeNudge::class]);

        $this->scheduleDailyNudgeAt('2026-07-21 09:00:00');

        $this->travelTo(Carbon::parse('2026-07-21 05:30:00', 'UTC'));

        $content = ScheduledContent::query()->withoutGlobalScope('academy')->firstOrFail();
        $this->assertTrue($content->claim());
        $this->assertFalse($content->claim());

        $this->assertSame(0, (new DispatchScheduledContents)->handle());
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function a_re_engagement_row_carries_its_inactive_day_threshold(): void
    {
        Bus::fake([SendReEngagementCampaign::class]);

        TenantContext::runFor($this->tehran, fn () => ScheduledContent::scheduleLocal(
            $this->tehran,
            ScheduledContentType::ReEngagement,
            '2026-07-21 10:00:00',
            'weekly',
            ['payload' => ['inactive_days' => 14]],
        ));

        $this->travelTo(Carbon::parse('2026-07-21 06:30:00', 'UTC'));
        (new DispatchScheduledContents)->handle();

        Bus::assertDispatched(
            SendReEngagementCampaign::class,
            static fn (SendReEngagementCampaign $job): bool => $job->inactiveDays === 14
        );
    }

    private function scheduleDailyNudgeAt(string $local, ?string $repeat = 'daily'): ScheduledContent
    {
        return TenantContext::runFor($this->tehran, fn (): ScheduledContent => ScheduledContent::scheduleLocal(
            $this->tehran,
            ScheduledContentType::DailyPractice,
            $local,
            $repeat,
        ));
    }
}
