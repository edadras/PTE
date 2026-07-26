<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Models\Student;
use App\Domain\Notification\Contracts\SmsDriver;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Domain\Notification\Services\NullSmsDriver;
use App\Domain\Telegram\Jobs\SendTelegramMessage;
use App\Domain\Telegram\Models\TelegramIdentity;
use App\Domain\Tenancy\Data\PlaceholderContext;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        $this->academy = Academy::factory()->configured()->create(['timezone' => 'Asia/Tehran']);
        TenantContext::set($this->academy);

        $this->student = Student::factory()->create([
            'email' => 'sara@example.test',
            'phone' => '09120000000',
        ]);
    }

    #[Test]
    public function a_telegram_notification_is_queued_for_a_reachable_student(): void
    {
        Bus::fake([SendTelegramMessage::class]);
        $this->linkTelegram();

        $sent = app(NotificationDispatcher::class)->send($this->student, 'daily_nudge');

        $this->assertCount(1, $sent);
        $this->assertSame(NotificationStatus::Sent, $sent->first()?->refresh()->status);

        Bus::assertDispatched(SendTelegramMessage::class);
    }

    #[Test]
    public function a_student_with_no_telegram_chat_is_skipped_not_failed(): void
    {
        Bus::fake([SendTelegramMessage::class]);

        $notification = app(NotificationDispatcher::class)->send($this->student, 'daily_nudge')->first();

        $this->assertSame(NotificationStatus::Skipped, $notification?->refresh()->status);
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function the_academys_own_wording_wins_over_the_platform_default(): void
    {
        Bus::fake([SendTelegramMessage::class]);
        $this->linkTelegram();

        MessageTemplate::query()->create([
            'key' => 'daily_nudge',
            'locale' => 'fa',
            'channel' => MessageTemplate::CHANNEL_TELEGRAM,
            'content' => 'وقت تمرین است، {first_name}!',
            'is_customized' => true,
        ]);

        $notification = app(NotificationDispatcher::class)
            ->send($this->student, 'daily_nudge', ['first_name' => 'Sara'])
            ->first();

        $this->assertNotNull($notification);

        $body = app(NotificationDispatcher::class)->body(
            $notification,
            PlaceholderContext::make(['first_name' => 'Sara'])->forAcademy($this->academy),
            $this->academy,
        );

        $this->assertStringContainsString('وقت تمرین است، Sara!', $body);
    }

    #[Test]
    public function an_email_notification_is_actually_mailed(): void
    {
        Mail::fake();

        $notification = app(NotificationDispatcher::class)
            ->send($this->student, 'daily_nudge', [], [NotificationChannel::Email])
            ->first();

        $this->assertSame(NotificationStatus::Sent, $notification?->refresh()->status);
    }

    #[Test]
    public function the_null_sms_driver_marks_the_row_skipped_rather_than_sent(): void
    {
        $this->assertInstanceOf(NullSmsDriver::class, app(SmsDriver::class));

        $notification = app(NotificationDispatcher::class)
            ->send($this->student, 'daily_nudge', [], [NotificationChannel::Sms])
            ->first();

        $notification?->refresh();

        $this->assertSame(NotificationStatus::Skipped, $notification?->status);
        $this->assertNull($notification?->sent_at);
        $this->assertStringContainsString('null', (string) $notification?->error);
    }

    #[Test]
    public function a_future_notification_is_queued_and_only_delivered_when_it_comes_due(): void
    {
        Bus::fake([SendTelegramMessage::class]);
        $this->linkTelegram();

        $notification = app(NotificationDispatcher::class)
            ->send($this->student, 'daily_nudge', [], [NotificationChannel::Telegram], now()->addHour())
            ->first();

        $this->assertSame(NotificationStatus::Queued, $notification?->status);
        Bus::assertNothingDispatched();

        $this->assertSame(0, app(NotificationDispatcher::class)->flushDue());

        $this->travel(2)->hours();

        $this->assertSame(1, app(NotificationDispatcher::class)->flushDue());
        $this->assertSame(NotificationStatus::Sent, $notification?->refresh()->status);
    }

    #[Test]
    public function notifications_never_cross_the_academy_boundary(): void
    {
        Bus::fake([SendTelegramMessage::class]);

        app(NotificationDispatcher::class)->send($this->student, 'daily_nudge');

        $other = Academy::factory()->configured()->create();

        TenantContext::runFor($other, function (): void {
            $this->assertSame(0, Notification::query()->count());
        });

        $this->assertSame(1, Notification::query()->count());
    }

    private function linkTelegram(): void
    {
        TelegramIdentity::factory()->create([
            'student_id' => $this->student->getKey(),
            'telegram_user_id' => 777,
            'chat_id' => 777,
        ]);
    }
}
