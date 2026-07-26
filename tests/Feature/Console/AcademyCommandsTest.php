<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\PlatformAuditLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyBrand;
use App\Domain\Tenancy\Models\AcademySettings;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AcademyCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);
    }

    #[Test]
    public function it_creates_an_academy_with_its_owner_settings_and_brand(): void
    {
        $this->artisan('academy:create', [
            'name' => 'Parsian Language Centre',
            'owner-email' => 'owner@parsian.test',
            '--slug' => 'parsian',
        ])->assertSuccessful();

        $academy = Academy::query()->withoutGlobalScopes()->where('slug', 'parsian')->first();

        $this->assertNotNull($academy);
        $this->assertSame(AcademyStatus::Active, $academy->status);

        $this->assertTrue(AcademySettings::query()->withoutGlobalScope('academy')->where('academy_id', $academy->getKey())->exists());
        $this->assertTrue(AcademyBrand::query()->withoutGlobalScope('academy')->where('academy_id', $academy->getKey())->exists());

        $owner = User::query()->where('email', 'owner@parsian.test')->first();
        $this->assertNotNull($owner);
        $this->assertSame((int) $owner->getKey(), (int) $academy->owner_user_id);

        $this->assertTrue(
            PlatformAuditLog::query()->where('action', AuditAction::AcademyCreated->value)->forAcademy($academy)->exists()
        );
    }

    #[Test]
    public function a_malformed_owner_email_is_rejected_before_anything_is_written(): void
    {
        $this->artisan('academy:create', ['name' => 'Broken', 'owner-email' => 'not-an-email'])
            ->assertExitCode(Command::INVALID);

        $this->assertSame(0, Academy::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_reserved_slug_is_refused(): void
    {
        $this->artisan('academy:create', [
            'name' => 'Admin Panel',
            'owner-email' => 'a@b.test',
            '--slug' => 'admin',
        ])->assertFailed();

        $this->assertSame(0, Academy::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function suspend_and_resume_move_the_status_and_record_the_reason(): void
    {
        $academy = Academy::factory()->configured()->create(['slug' => 'parsian']);

        $this->artisan('academy:suspend', ['id' => 'parsian', '--reason' => 'unpaid invoice'])
            ->assertSuccessful();

        $this->assertSame(AcademyStatus::Suspended, $academy->refresh()->status);
        $this->assertSame(
            'unpaid invoice',
            AcademySettings::query()
                ->withoutGlobalScope('academy')
                ->where('academy_id', $academy->getKey())
                ->first()?->features['suspension_reason'] ?? null
        );

        $this->artisan('academy:resume', ['id' => (string) $academy->getKey()])->assertSuccessful();

        $this->assertSame(AcademyStatus::Active, $academy->refresh()->status);

        $this->assertTrue(
            PlatformAuditLog::query()->where('action', AuditAction::AcademySuspended->value)->exists()
        );
        $this->assertTrue(
            PlatformAuditLog::query()->where('action', AuditAction::AcademyResumed->value)->exists()
        );
    }

    #[Test]
    public function the_list_shows_each_academy_with_its_student_count(): void
    {
        $academy = Academy::factory()->configured()->create(['slug' => 'parsian', 'name' => 'Parsian']);
        TenantContext::runFor($academy, fn () => Student::factory()->count(3)->create());

        $this->artisan('academy:list')
            ->expectsOutputToContain('parsian')
            ->assertSuccessful();

        $this->artisan('academy:list', ['--json' => true])->assertSuccessful();
    }

    #[Test]
    public function cloning_copies_the_brand_and_settings_without_the_students(): void
    {
        $source = Academy::factory()->configured()->create(['slug' => 'source']);

        AcademyBrand::query()->where('academy_id', $source->getKey())->update([
            'primary_color' => '#123456',
            'tagline' => 'Speak with confidence',
        ]);

        TenantContext::runFor($source, fn () => Student::factory()->count(4)->create());

        $this->artisan('academy:clone', [
            'from' => 'source',
            'to' => 'Source Two',
            '--slug' => 'source-two',
        ])->assertSuccessful();

        $target = Academy::query()->withoutGlobalScopes()->where('slug', 'source-two')->first();

        $this->assertNotNull($target);

        $brand = AcademyBrand::query()->withoutGlobalScope('academy')->where('academy_id', $target->getKey())->first();
        $this->assertSame('#123456', $brand?->primary_color);
        $this->assertSame('Speak with confidence', $brand?->tagline);

        TenantContext::runFor($target, function (): void {
            $this->assertSame(0, Student::query()->count(), 'A clone must not carry the source academy students.');
        });

        $this->assertTrue(
            PlatformAuditLog::query()->where('action', AuditAction::AcademyCloned->value)->forAcademy($target)->exists()
        );
    }

    #[Test]
    public function cloning_with_content_copies_the_question_banks_as_owned_rows(): void
    {
        $source = Academy::factory()->configured()->create(['slug' => 'source']);

        TenantContext::runFor($source, function (): void {
            $bank = QuestionBank::factory()->create(['name' => 'Starter pack']);
            Question::factory()->count(2)->create(['bank_id' => $bank->getKey()]);
        });

        $this->artisan('academy:clone', [
            'from' => 'source',
            'to' => 'Source Two',
            '--slug' => 'source-two',
            '--content' => true,
        ])->assertSuccessful();

        $target = Academy::query()->withoutGlobalScopes()->where('slug', 'source-two')->firstOrFail();

        TenantContext::runFor($target, function (): void {
            $this->assertSame(1, QuestionBank::query()->count());
            $this->assertSame(2, Question::query()->count());
        });

        // A copy, not a share: the source keeps its own rows untouched.
        TenantContext::runFor($source, function (): void {
            $this->assertSame(2, Question::query()->count());
        });
    }

    #[Test]
    public function platform_stats_reports_across_every_academy(): void
    {
        $alpha = Academy::factory()->configured()->create();
        $beta = Academy::factory()->configured()->create();

        TenantContext::runFor($alpha, fn () => Student::factory()->count(2)->create());
        TenantContext::runFor($beta, fn () => Student::factory()->count(3)->create());

        $this->artisan('platform:stats', ['--json' => true])->assertSuccessful();
        $this->artisan('platform:stats')->expectsOutputToContain('academies')->assertSuccessful();
    }
}
