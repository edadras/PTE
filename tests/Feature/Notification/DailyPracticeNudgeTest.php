<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Identity\Models\Student;
use App\Domain\Notification\Jobs\SendDailyPracticeNudge;
use App\Domain\Notification\Jobs\SendReEngagementCampaign;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Services\AudienceResolver;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Domain\Reporting\Services\StatCollector;
use App\Domain\Telegram\Jobs\SendTelegramMessage;
use App\Domain\Telegram\Models\TelegramIdentity;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DailyPracticeNudgeTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SendTelegramMessage::class]);

        $this->academy = Academy::factory()->configured()->create(['timezone' => 'Asia/Tehran']);
        TenantContext::set($this->academy);
    }

    #[Test]
    public function it_nudges_only_students_who_have_not_practised_today_in_the_academys_timezone(): void
    {
        // 21:00 UTC on the 20th is already 00:30 on the 21st in Tehran, so an
        // answer submitted "now" belongs to the 21st locally.
        $this->travelTo(Carbon::parse('2026-07-20 21:00:00', 'UTC'));

        $practised = $this->reachableStudent();
        $idle = $this->reachableStudent();

        Answer::factory()->create([
            'student_id' => $practised->getKey(),
            'session_type' => SessionType::Practice,
            'session_id' => 1,
            'created_at' => now(),
        ]);

        $sent = $this->runNudge();

        $this->assertSame(1, $sent);
        $this->assertSame(
            (int) $idle->getKey(),
            (int) Notification::query()->first()?->notifiable_id,
        );
    }

    #[Test]
    public function an_answer_from_the_previous_local_day_does_not_count_as_practised_today(): void
    {
        $this->travelTo(Carbon::parse('2026-07-21 06:00:00', 'UTC'));

        $student = $this->reachableStudent();

        // 2026-07-20 19:00 UTC is 22:30 on the 20th in Tehran: yesterday.
        Answer::factory()->create([
            'student_id' => $student->getKey(),
            'session_type' => SessionType::Practice,
            'session_id' => 1,
            'created_at' => Carbon::parse('2026-07-20 19:00:00', 'UTC'),
        ]);

        $this->assertSame(1, $this->runNudge());
    }

    #[Test]
    public function students_without_a_reachable_telegram_chat_are_not_nudged(): void
    {
        Student::factory()->create();

        $this->assertSame(0, $this->runNudge());
    }

    #[Test]
    public function the_re_engagement_campaign_only_targets_lapsed_students_and_not_twice(): void
    {
        $lapsed = $this->reachableStudent(['last_active_at' => now()->subDays(20)]);
        $this->reachableStudent(['last_active_at' => now()->subDay()]);

        $job = new SendReEngagementCampaign((int) $this->academy->getKey(), 7);

        $this->assertSame(1, $job->handle(app(AudienceResolver::class), app(NotificationDispatcher::class)));
        $this->assertSame(
            (int) $lapsed->getKey(),
            (int) Notification::query()->first()?->notifiable_id,
        );

        // Running again inside the cooldown must not message the same person.
        $this->assertSame(0, $job->handle(app(AudienceResolver::class), app(NotificationDispatcher::class)));
    }

    private function runNudge(): int
    {
        return (new SendDailyPracticeNudge((int) $this->academy->getKey()))->handle(
            app(AudienceResolver::class),
            app(NotificationDispatcher::class),
            app(StatCollector::class),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reachableStudent(array $attributes = []): Student
    {
        $student = Student::factory()->create($attributes);

        TelegramIdentity::factory()->create(['student_id' => $student->getKey()]);

        return $student;
    }
}
