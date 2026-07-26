<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Assessment\Actions\OverrideAnswerScore;
use App\Domain\Assessment\Enums\ScoredBy;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\Models\PlatformAuditLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\Student;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        $this->academy = Academy::factory()->configured()->create();
        TenantContext::set($this->academy);
    }

    #[Test]
    public function a_teacher_score_override_writes_an_audit_entry_with_the_reason(): void
    {
        $student = Student::factory()->create();
        $teacher = User::factory()->create();

        $answer = Answer::factory()->create([
            'student_id' => $student->getKey(),
            'session_type' => SessionType::Practice,
            'session_id' => 1,
            'score' => 42.0,
            'max_score' => 90.0,
            'scored_by' => ScoredBy::Ai,
        ]);

        app(OverrideAnswerScore::class)->handle(
            $answer,
            78.0,
            'The transcript was cut off by a network drop.',
            (int) $teacher->getKey(),
        );

        $entry = ActivityLog::query()
            ->forAction(AuditAction::ScoreOverridden->value)
            ->forSubject($answer)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(42.0, (float) $entry->old_values['score']);
        $this->assertSame(78.0, (float) $entry->new_values['score']);
        $this->assertSame('The transcript was cut off by a network drop.', $entry->new_values['reason']);
        $this->assertSame((int) $teacher->getKey(), (int) $entry->new_values['overridden_by']);
        $this->assertSame((int) $this->academy->getKey(), (int) $entry->academy_id);
    }

    #[Test]
    public function an_audit_entry_can_never_be_updated_or_deleted(): void
    {
        $entry = app(AuditRecorder::class)->record(AuditAction::TicketOpened, null, [], ['a' => 1]);

        $this->assertNotNull($entry);

        try {
            $entry->forceFill(['action' => 'tampered'])->save();
            $this->fail('activity_logs accepted an update.');
        } catch (LogicException) {
            // expected
        }

        try {
            $entry->delete();
            $this->fail('activity_logs accepted a delete.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame(AuditAction::TicketOpened->value, (string) $entry->fresh()?->action);
    }

    #[Test]
    public function retention_is_the_only_sanctioned_removal_path(): void
    {
        app(AuditRecorder::class)->record(AuditAction::TicketOpened, null, [], ['old' => true]);

        ActivityLog::query()->withoutGlobalScope('academy')->update(['created_at' => now()->subDays(400)]);

        app(AuditRecorder::class)->record(AuditAction::TicketClosed, null, [], ['new' => true]);

        $pruned = ActivityLog::pruneOlderThan(now()->subDays(365));

        $this->assertSame(1, $pruned);
        $this->assertSame(1, ActivityLog::query()->count());
    }

    #[Test]
    public function secrets_are_redacted_before_they_reach_the_trail(): void
    {
        $bot = TelegramBot::factory()->create();

        $entry = app(AuditRecorder::class)->record(
            AuditAction::BotTokenRotated,
            $bot,
            ['token' => '123:OLD_SECRET_VALUE'],
            ['token' => '123:NEW_SECRET_VALUE', 'webhook_secret' => 'abcd', 'username' => 'demo_bot'],
        );

        $this->assertNotNull($entry);
        $this->assertSame('[redacted]', $entry->old_values['token']);
        $this->assertSame('[redacted]', $entry->new_values['token']);
        $this->assertSame('[redacted]', $entry->new_values['webhook_secret']);
        $this->assertSame('demo_bot', $entry->new_values['username']);
    }

    #[Test]
    public function the_authenticated_user_is_captured_as_the_actor(): void
    {
        $user = User::factory()->create(['name' => 'Ops Person']);
        $this->actingAs($user);

        $entry = app(AuditRecorder::class)->record(AuditAction::TicketAssigned, null, [], ['x' => 1]);

        $this->assertSame('user', $entry?->actor_type);
        $this->assertSame((int) $user->getKey(), (int) $entry?->actor_id);
        $this->assertSame('Ops Person', $entry?->actor_label);
    }

    #[Test]
    public function a_platform_action_is_mirrored_into_the_academy_trail(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        app(AuditRecorder::class)
            ->actingAs($superAdmin)
            ->recordPlatform(AuditAction::Impersonated, $this->academy, ['as_student_id' => 7]);

        $platform = PlatformAuditLog::query()->forAcademy($this->academy)->first();

        $this->assertNotNull($platform);
        $this->assertSame((int) $superAdmin->getKey(), (int) $platform->user_id);
        $this->assertSame(7, (int) $platform->payload['as_student_id']);

        $this->assertTrue(
            ActivityLog::query()->forAction(AuditAction::Impersonated->value)->exists(),
            'The academy must be able to see that it was impersonated.'
        );

        app(AuditRecorder::class)->forgetActor();
    }

    #[Test]
    public function activity_logs_never_cross_the_academy_boundary(): void
    {
        app(AuditRecorder::class)->record(AuditAction::TicketOpened, null, [], ['a' => 1]);

        $other = Academy::factory()->configured()->create();

        TenantContext::runFor($other, function (): void {
            $this->assertSame(0, ActivityLog::query()->count());
        });

        $this->assertSame(1, ActivityLog::query()->count());
    }
}
