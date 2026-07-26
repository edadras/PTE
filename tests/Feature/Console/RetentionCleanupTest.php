<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\AI\Models\AiLog;
use App\Domain\AI\Models\AiRequest;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Audit\Services\RetentionSweeper;
use App\Domain\Identity\Models\Student;
use App\Domain\Reporting\Enums\ReportStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Models\Report;
use App\Domain\Telegram\Enums\MessageDirection;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramMessage;
use App\Domain\Telegram\Models\TelegramUpdate;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademySettings;
use App\Domain\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RetentionCleanupTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    private TelegramBot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        Storage::fake('tenant');

        config()->set('pte.retention.telegram_updates', 30);
        config()->set('pte.retention.telegram_messages', 90);
        config()->set('pte.retention.ai_logs', 7);
        config()->set('pte.retention.activity_logs', 365);

        $this->academy = Academy::factory()->configured()->create();
        TenantContext::set($this->academy);

        $this->bot = TelegramBot::factory()->create();
    }

    #[Test]
    public function it_removes_exactly_what_is_past_its_window_and_nothing_else(): void
    {
        $this->telegramUpdate(now()->subDays(45));
        $this->telegramUpdate(now()->subDays(10));

        $this->telegramMessage(now()->subDays(120));
        $this->telegramMessage(now()->subDays(30));

        $this->aiLog(now()->subDays(30));
        $this->aiLog(now()->subDay());

        $result = app(RetentionSweeper::class)->sweep();

        $this->assertSame(1, $result['telegram_updates']);
        $this->assertSame(1, $result['telegram_messages']);
        $this->assertSame(1, $result['ai_logs']);

        $this->assertSame(1, TelegramUpdate::query()->count());
        $this->assertSame(1, TelegramMessage::query()->count());
        $this->assertSame(1, AiLog::query()->count());
    }

    #[Test]
    public function a_dry_run_counts_without_deleting(): void
    {
        $this->telegramUpdate(now()->subDays(45));

        $result = app(RetentionSweeper::class)->sweep(dryRun: true);

        $this->assertSame(1, $result['telegram_updates']);
        $this->assertSame(1, TelegramUpdate::query()->count());
    }

    #[Test]
    public function expired_exports_lose_their_file_but_keep_their_audit_row(): void
    {
        $report = $this->report(expiresAt: now()->subDay());
        $fresh = $this->report(expiresAt: now()->addDay());

        Storage::disk('tenant')->put((string) $report->file_path, 'data');
        Storage::disk('tenant')->put((string) $fresh->file_path, 'data');

        $result = app(RetentionSweeper::class)->sweep();

        $this->assertSame(1, $result['reports']);

        $report->refresh();
        $this->assertSame(ReportStatus::Expired, $report->status);
        $this->assertNull($report->file_path);
        Storage::disk('tenant')->assertMissing('exports/expired.csv');

        $fresh->refresh();
        $this->assertSame(ReportStatus::Ready, $fresh->status);
        Storage::disk('tenant')->assertExists('exports/fresh.csv');
    }

    #[Test]
    public function answer_media_is_pruned_on_the_academys_own_window_but_the_score_survives(): void
    {
        AcademySettings::query()
            ->where('academy_id', $this->academy->getKey())
            ->update(['data_retention_days' => 30]);

        $this->academy->unsetRelation('settings');

        $student = Student::factory()->create();

        $old = Answer::factory()->create([
            'student_id' => $student->getKey(),
            'session_type' => SessionType::Practice,
            'session_id' => 1,
            'media_path' => 'answers/old.ogg',
            'score' => 55,
            'created_at' => now()->subDays(45),
        ]);

        $recent = Answer::factory()->create([
            'student_id' => $student->getKey(),
            'session_type' => SessionType::Practice,
            'session_id' => 1,
            'media_path' => 'answers/recent.ogg',
            'created_at' => now()->subDays(5),
        ]);

        Storage::disk('tenant')->put('answers/old.ogg', 'audio');
        Storage::disk('tenant')->put('answers/recent.ogg', 'audio');

        $result = app(RetentionSweeper::class)->sweep();

        $this->assertSame(1, $result['answer_media']);

        $old->refresh();
        $this->assertNull($old->media_path);
        // The grade is not media and must survive.
        $this->assertSame(55.0, (float) $old->score);

        $this->assertSame('answers/recent.ogg', $recent->refresh()->media_path);
    }

    #[Test]
    public function an_academy_cannot_extend_media_retention_beyond_the_platform_cap(): void
    {
        config()->set('pte.media.answer_retention_days', 90);

        AcademySettings::query()
            ->where('academy_id', $this->academy->getKey())
            ->update(['data_retention_days' => 3650]);

        $this->academy->unsetRelation('settings');
        $this->academy->load('settings');

        $this->assertSame(90, app(RetentionSweeper::class)->mediaRetentionDaysFor($this->academy));
    }

    #[Test]
    public function activity_logs_older_than_the_window_go_and_the_rest_stay(): void
    {
        $recorder = app(AuditRecorder::class);
        $recorder->record(AuditAction::TicketOpened, null, [], ['old' => true]);
        ActivityLog::query()->withoutGlobalScope('academy')->update(['created_at' => now()->subDays(400)]);

        $recorder->record(AuditAction::TicketClosed, null, [], ['new' => true]);

        $result = app(RetentionSweeper::class)->sweep();

        $this->assertSame(1, $result['activity_logs']);
        $this->assertSame(1, ActivityLog::query()->count());
    }

    #[Test]
    public function the_command_reports_what_it_removed(): void
    {
        $this->telegramUpdate(now()->subDays(45));

        $this->artisan('data:cleanup')->assertSuccessful();

        $this->assertSame(0, TelegramUpdate::query()->count());
    }

    #[Test]
    public function the_command_can_be_asked_not_to_delete_anything(): void
    {
        $this->telegramUpdate(now()->subDays(45));

        $this->artisan('data:cleanup', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, TelegramUpdate::query()->count());
    }

    private function telegramUpdate(DateTimeInterface $at): void
    {
        TelegramUpdate::query()->create([
            'telegram_bot_id' => $this->bot->getKey(),
            'update_id' => random_int(1, 1_000_000_000),
            'type' => 'message',
            'payload' => ['ok' => true],
            'created_at' => $at,
        ]);
    }

    private function telegramMessage(DateTimeInterface $at): void
    {
        TelegramMessage::query()->create([
            'telegram_bot_id' => $this->bot->getKey(),
            'chat_id' => 42,
            'direction' => MessageDirection::Out,
            'message_type' => 'text',
            'content' => 'hi',
            'status' => 'sent',
            'created_at' => $at,
        ]);
    }

    private function aiLog(DateTimeInterface $at): void
    {
        $request = AiRequest::factory()->create(['created_at' => $at]);

        AiLog::query()->create([
            'ai_request_id' => $request->getKey(),
            'rendered_prompt' => 'prompt',
            'raw_response' => 'response',
            'created_at' => $at,
        ]);
    }

    private function report(DateTimeInterface $expiresAt): Report
    {
        $name = $expiresAt < now() ? 'expired' : 'fresh';

        /** @var Report $report */
        $report = Report::query()->create([
            'type' => ReportType::Students,
            'format' => 'csv',
            'status' => ReportStatus::Ready,
            'file_path' => "exports/{$name}.csv",
            'file_size' => 4,
            'row_count' => 1,
            'generated_at' => now()->subDays(2),
            'expires_at' => $expiresAt,
        ]);

        return $report;
    }
}
