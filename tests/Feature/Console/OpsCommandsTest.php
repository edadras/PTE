<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\AI\Enums\AiRequestStatus;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Models\AiRequest;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Database\Seeders\DefaultPromptSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The remaining ops CLI: registration, argument validation and the read-only
 * reports. The commands that talk to Telegram or a model provider are exercised
 * only up to the point where they would leave the process.
 */
final class OpsCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        $this->academy = Academy::factory()->configured()->create(['slug' => 'parsian']);
    }

    #[Test]
    public function every_documented_ops_command_is_registered(): void
    {
        $registered = array_keys($this->app->make(Kernel::class)->all());

        foreach ([
            'academy:create', 'academy:suspend', 'academy:resume', 'academy:export',
            'academy:clone', 'academy:list', 'telegram:register-webhook',
            'telegram:health-check', 'telegram:reset-webhook', 'ai:test-prompt',
            'ai:cost-report', 'ai:rescore', 'tenant:run', 'platform:stats', 'data:cleanup',
        ] as $signature) {
            $this->assertContains($signature, $registered, "{$signature} is not registered.");
        }
    }

    #[Test]
    public function the_cost_report_totals_spend_for_the_period(): void
    {
        TenantContext::runFor($this->academy, function (): void {
            AiRequest::factory()->count(2)->create([
                'academy_id' => $this->academy->getKey(),
                'cost_usd' => 0.5,
                'total_tokens' => 1000,
                'status' => AiRequestStatus::Success,
                'created_at' => now(),
            ]);
        });

        $this->artisan('ai:cost-report', ['--period' => now()->format('Y-m'), '--json' => true])
            ->assertSuccessful();

        $this->artisan('ai:cost-report')
            ->expectsOutputToContain('parsian')
            ->assertSuccessful();
    }

    #[Test]
    public function a_malformed_period_is_rejected(): void
    {
        $this->artisan('ai:cost-report', ['--period' => 'july'])->assertExitCode(Command::INVALID);
    }

    #[Test]
    public function the_health_check_insists_on_a_target(): void
    {
        $this->artisan('telegram:health-check')->assertExitCode(Command::INVALID);
        $this->artisan('telegram:health-check', ['--all' => true])->assertSuccessful();
    }

    #[Test]
    public function registering_a_webhook_for_an_academy_without_a_bot_fails_clearly(): void
    {
        $this->artisan('telegram:register-webhook', ['academy' => 'parsian'])->assertFailed();
    }

    #[Test]
    public function test_prompt_rejects_an_unknown_task_key(): void
    {
        $this->artisan('ai:test-prompt', ['academy' => 'parsian', 'key' => 'not.a.task'])
            ->assertExitCode(Command::INVALID);
    }

    #[Test]
    public function test_prompt_renders_the_platform_preamble_for_a_known_task(): void
    {
        $this->seed(DefaultPromptSeeder::class);

        $this->artisan('ai:test-prompt', [
            'academy' => 'parsian',
            'key' => AiTaskKey::WritingEssay->value,
        ])->expectsOutputToContain('PLATFORM RULES')->assertSuccessful();
    }

    #[Test]
    public function test_prompt_reports_a_task_the_academy_has_no_published_prompt_for(): void
    {
        $this->artisan('ai:test-prompt', [
            'academy' => 'parsian',
            'key' => AiTaskKey::WritingEssay->value,
        ])->assertFailed();
    }

    #[Test]
    public function rescoring_refuses_a_grade_a_teacher_has_already_fixed(): void
    {
        $answer = TenantContext::runFor($this->academy, function (): Answer {
            $student = Student::factory()->create();

            return Answer::factory()->create([
                'student_id' => $student->getKey(),
                'session_type' => SessionType::Practice,
                'session_id' => 1,
                'graded_manually' => true,
                'score' => 80,
            ]);
        });

        $this->artisan('ai:rescore', ['answer' => (string) $answer->getKey()])
            ->assertExitCode(Command::INVALID);
    }

    #[Test]
    public function rescoring_an_unknown_answer_fails_cleanly(): void
    {
        $this->artisan('ai:rescore', ['answer' => '999999'])->assertFailed();
    }
}
