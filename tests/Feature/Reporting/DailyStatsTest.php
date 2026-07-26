<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Identity\Models\Student;
use App\Domain\Reporting\Enums\StatMetric;
use App\Domain\Reporting\Jobs\AggregateDailyStats;
use App\Domain\Reporting\Models\DailyStat;
use App\Domain\Reporting\Services\DashboardStats;
use App\Domain\Reporting\Services\StatCollector;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DailyStatsTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academy = Academy::factory()->configured()->create(['timezone' => 'Asia/Tehran']);
        TenantContext::set($this->academy);
    }

    #[Test]
    public function the_nightly_job_writes_one_row_per_metric_for_the_day(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 12:00:00', 'UTC'));

        $student = Student::factory()->create(['created_at' => now()->subDay()]);

        PracticeSession::factory()->count(2)->create([
            'student_id' => $student->getKey(),
            'created_at' => now()->subDay(),
        ]);

        Answer::factory()->count(3)->create([
            'student_id' => $student->getKey(),
            'session_type' => SessionType::Practice,
            'session_id' => 1,
            'score' => 60,
            'max_score' => 90,
            'created_at' => now()->subDay(),
        ]);

        (new AggregateDailyStats((int) $this->academy->getKey()))->handle(app(StatCollector::class));

        $yesterday = now(app(StatCollector::class)->timezoneFor($this->academy))->subDay()->toDateString();

        $this->assertSame(
            2.0,
            (float) DailyStat::query()->metric(StatMetric::PracticeSessions)->where('date', $yesterday)->value('value')
        );
        $this->assertSame(
            3.0,
            (float) DailyStat::query()->metric(StatMetric::AnswersSubmitted)->where('date', $yesterday)->value('value')
        );
        // 180 of 270 possible marks.
        $this->assertSame(
            66.67,
            (float) DailyStat::query()->metric(StatMetric::AverageScore)->where('date', $yesterday)->value('value')
        );
    }

    #[Test]
    public function rerunning_the_aggregation_overwrites_rather_than_doubles(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 12:00:00', 'UTC'));

        Student::factory()->count(4)->create(['created_at' => now()->subDay()]);

        $job = new AggregateDailyStats((int) $this->academy->getKey());
        $job->handle(app(StatCollector::class));
        $job->handle(app(StatCollector::class));

        $rows = DailyStat::query()->metric(StatMetric::StudentsNew)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(4.0, (float) $rows->first()?->value);
    }

    #[Test]
    public function a_day_is_the_academys_calendar_day_not_the_servers(): void
    {
        // 22:30 UTC on the 20th is already 02:00 on the 21st in Tehran.
        $this->travelTo(Carbon::parse('2026-07-20 22:30:00', 'UTC'));

        $collector = app(StatCollector::class);
        [$from, $to] = $collector->window($this->academy, Carbon::parse('2026-07-21', 'Asia/Tehran'));

        // Tehran is UTC+03:30 all year (Iran dropped DST in 2022).
        $this->assertSame('2026-07-20 20:30:00', $from->utc()->toDateTimeString());
        $this->assertSame('2026-07-21 20:29:59', $to->utc()->toDateTimeString());
    }

    #[Test]
    public function the_dashboard_reads_stored_stats_for_closed_days_and_live_data_for_today(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 12:00:00', 'UTC'));

        $dashboard = app(DashboardStats::class);
        $today = $dashboard->today($this->academy);

        // A closed day comes from daily_stats even though no session rows exist.
        DailyStat::put((int) $this->academy->getKey(), $today->copy()->subDay(), StatMetric::PracticeSessions, 11.0);

        Student::factory()->count(2)->create();
        PracticeSession::factory()->create(['student_id' => 1]);

        $widgets = collect($dashboard->widgets($this->academy))->keyBy('key');

        $this->assertSame(2.0, $widgets['students']['value']);
        $this->assertSame(1.0, $widgets['practice_today']['value']);
        $this->assertSame(11.0, $widgets['practice_today']['previous']);
        $this->assertSame(-10.0, $widgets['practice_today']['delta']);

        $this->assertSame(
            ['students', 'practice_today', 'exams_today', 'ai_requests', 'average_score', 'revenue', 'new_users', 'active_users'],
            $widgets->keys()->all(),
        );
    }

    #[Test]
    public function a_series_rolls_up_the_way_the_metric_allows(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 12:00:00', 'UTC'));

        $dashboard = app(DashboardStats::class);
        $today = $dashboard->today($this->academy);

        DailyStat::put((int) $this->academy->getKey(), $today->copy()->subDays(2), StatMetric::PracticeSessions, 4.0);
        DailyStat::put((int) $this->academy->getKey(), $today->copy()->subDay(), StatMetric::PracticeSessions, 6.0);
        DailyStat::put((int) $this->academy->getKey(), $today->copy()->subDays(2), StatMetric::AverageScore, 50.0);
        DailyStat::put((int) $this->academy->getKey(), $today->copy()->subDay(), StatMetric::AverageScore, 70.0);

        $from = $today->copy()->subDays(2);
        $to = $today->copy()->subDay();

        $this->assertSame(10.0, $dashboard->summary(StatMetric::PracticeSessions, $from, $to, $this->academy));
        $this->assertSame(60.0, $dashboard->summary(StatMetric::AverageScore, $from, $to, $this->academy));
    }

    #[Test]
    public function stats_never_cross_the_academy_boundary(): void
    {
        $today = app(DashboardStats::class)->today($this->academy);

        DailyStat::put((int) $this->academy->getKey(), $today, StatMetric::PracticeSessions, 9.0);

        $other = Academy::factory()->configured()->create();

        TenantContext::runFor($other, function (): void {
            $this->assertSame(0, DailyStat::query()->count());
        });
    }
}
