<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\AI\Actions\PublishPrompt;
use App\Domain\AI\Actions\PublishRubric;
use App\Domain\AI\Enums\PromptStatus;
use App\Domain\AI\Exceptions\InvalidRubricException;
use App\Domain\AI\Models\AiPrompt;
use App\Domain\AI\Models\AiRubric;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\Models\PlatformAuditLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Telegram\Events\BotTokenRotated;
use App\Domain\Telegram\Events\BotWebhookReset;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Tenancy\Actions\DeleteAcademy;
use App\Domain\Tenancy\Actions\ResumeAcademy;
use App\Domain\Tenancy\Events\AcademyCloned;
use App\Domain\Tenancy\Events\AcademyCreated;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every AuditAction docs/02 §7 makes mandatory must be reachable through a
 * dispatched event and its registered listener — this file proves the wiring
 * for the cases the domain layer emits.
 */
final class MandatoryAuditActionsTest extends TestCase
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
    public function a_bot_token_rotation_is_audited_and_the_row_never_contains_the_token(): void
    {
        $token = '1234567890:'.str_repeat('A', 35);
        $bot = TelegramBot::factory()->create([
            'token' => $token,
            'token_last4' => mb_substr($token, -4),
        ]);

        BotTokenRotated::dispatch($bot);

        $entry = ActivityLog::query()
            ->forAction(AuditAction::BotTokenRotated->value)
            ->forSubject($bot)
            ->first();

        $this->assertNotNull($entry);
        // AuditRecorder redacts any key containing "token" — even the display
        // suffix never reaches the trail in the clear.
        $this->assertSame('[redacted]', $entry->new_values['token_last4']);
        $this->assertSame($bot->username, $entry->new_values['username']);

        $serialised = (string) json_encode([$entry->old_values, $entry->new_values]);

        $this->assertStringNotContainsString($token, $serialised);
        $this->assertStringNotContainsString(str_repeat('A', 35), $serialised);
        $this->assertArrayNotHasKey('token', $entry->new_values);
        $this->assertArrayNotHasKey('webhook_secret', $entry->new_values);
    }

    #[Test]
    public function a_webhook_reset_is_audited_with_the_drop_pending_flag(): void
    {
        $bot = TelegramBot::factory()->active()->create();

        BotWebhookReset::dispatch($bot, true);

        $entry = ActivityLog::query()
            ->forAction(AuditAction::BotWebhookReset->value)
            ->forSubject($bot)
            ->first();

        $this->assertNotNull($entry);
        $this->assertTrue((bool) $entry->new_values['dropped_pending_updates']);
    }

    #[Test]
    public function publishing_a_prompt_archives_the_previous_version_and_is_audited(): void
    {
        $live = AiPrompt::factory()->create([
            'academy_id' => $this->academy->getKey(),
            'version' => 1,
        ]);
        $draft = AiPrompt::factory()->draft()->create([
            'academy_id' => $this->academy->getKey(),
            'version' => 2,
        ]);

        app(PublishPrompt::class)->handle($draft);

        $this->assertSame(PromptStatus::Archived, $live->refresh()->status);
        $this->assertSame(PromptStatus::Published, $draft->refresh()->status);
        $this->assertNotNull($draft->published_at);

        $entry = ActivityLog::query()
            ->forAction(AuditAction::PromptPublished->value)
            ->forSubject($draft)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(2, (int) $entry->new_values['version']);
    }

    #[Test]
    public function an_untested_prompt_cannot_be_published(): void
    {
        $draft = AiPrompt::factory()->draft()->create([
            'academy_id' => $this->academy->getKey(),
            'tested_at' => null,
        ]);

        $this->expectException(LogicException::class);

        app(PublishPrompt::class)->handle($draft);
    }

    #[Test]
    public function publishing_a_rubric_retires_the_previous_version_and_is_audited(): void
    {
        $live = AiRubric::factory()->create([
            'academy_id' => $this->academy->getKey(),
            'version' => 1,
        ]);
        $next = AiRubric::factory()->create([
            'academy_id' => $this->academy->getKey(),
            'version' => 2,
            'is_active' => false,
        ]);

        app(PublishRubric::class)->handle($next);

        $this->assertFalse($live->refresh()->is_active);
        $this->assertTrue($next->refresh()->is_active);

        $entry = ActivityLog::query()
            ->forAction(AuditAction::RubricPublished->value)
            ->forSubject($next)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(2, (int) $entry->new_values['version']);
        $this->assertSame(100, array_sum($entry->new_values['weights']));
    }

    #[Test]
    public function a_rubric_whose_weights_do_not_total_100_is_refused(): void
    {
        $rubric = AiRubric::factory()->make([
            'academy_id' => $this->academy->getKey(),
            'criteria' => [
                ['key' => 'content', 'label' => 'Content', 'weight' => 50, 'guidance' => ''],
            ],
        ]);

        $this->expectException(InvalidRubricException::class);

        app(PublishRubric::class)->handle($rubric);
    }

    #[Test]
    public function resuming_an_academy_is_audited_on_the_platform_trail(): void
    {
        $suspended = Academy::factory()->suspended()->create();

        app(ResumeAcademy::class)->handle($suspended);

        $this->assertTrue(
            PlatformAuditLog::query()
                ->where('action', AuditAction::AcademyResumed->value)
                ->where('target_academy_id', $suspended->getKey())
                ->exists(),
        );
    }

    #[Test]
    public function deleting_an_academy_is_audited_on_the_platform_trail(): void
    {
        $doomed = Academy::factory()->create();

        app(DeleteAcademy::class)->handle($doomed);

        $this->assertSoftDeleted('academies', ['id' => $doomed->getKey()]);
        $this->assertTrue(
            PlatformAuditLog::query()
                ->where('action', AuditAction::AcademyDeleted->value)
                ->where('target_academy_id', $doomed->getKey())
                ->exists(),
        );
    }

    #[Test]
    public function academy_creation_and_cloning_events_reach_the_platform_trail(): void
    {
        $source = Academy::factory()->create();
        $target = Academy::factory()->create();

        AcademyCreated::dispatch($target);
        AcademyCloned::dispatch($source, $target);

        $this->assertTrue(
            PlatformAuditLog::query()
                ->where('action', AuditAction::AcademyCreated->value)
                ->where('target_academy_id', $target->getKey())
                ->exists(),
        );

        $cloned = PlatformAuditLog::query()
            ->where('action', AuditAction::AcademyCloned->value)
            ->where('target_academy_id', $target->getKey())
            ->first();

        $this->assertNotNull($cloned);
        $this->assertSame((int) $source->getKey(), (int) $cloned->payload['source_academy_id']);
    }

    #[Test]
    public function platform_lifecycle_entries_are_mirrored_into_the_academy_trail(): void
    {
        $suspended = Academy::factory()->suspended()->create();

        app(ResumeAcademy::class)->handle($suspended);

        TenantContext::runFor($suspended, function (): void {
            $this->assertTrue(
                ActivityLog::query()->forAction(AuditAction::AcademyResumed->value)->exists(),
            );
        });
    }
}
