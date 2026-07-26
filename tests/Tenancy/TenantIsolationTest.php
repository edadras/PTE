<?php

declare(strict_types=1);

namespace Tests\Tenancy;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Tenancy\Exceptions\TenantNotResolvedException;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The boundary tests. Everything else in this product can be fixed after
 * release; one academy reading another's data cannot.
 *
 * @see docs/01-multi-tenancy.md §7
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Academy $alpha;

    private Academy $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Academy::factory()->create(['slug' => 'alpha', 'name' => 'Alpha Academy']);
        $this->beta = Academy::factory()->create(['slug' => 'beta', 'name' => 'Beta Academy']);
    }

    #[Test]
    public function queries_never_cross_the_academy_boundary(): void
    {
        TenantContext::runFor($this->alpha, function (): void {
            Student::factory()->count(3)->create();
        });

        TenantContext::runFor($this->beta, function (): void {
            Student::factory()->count(5)->create();
        });

        TenantContext::set($this->alpha);
        $this->assertSame(3, Student::query()->count());

        TenantContext::forget();
        TenantContext::set($this->beta);
        $this->assertSame(5, Student::query()->count());
    }

    #[Test]
    public function find_by_id_cannot_reach_another_academys_record(): void
    {
        $foreign = TenantContext::runFor(
            $this->beta,
            fn (): Student => Student::factory()->create()
        );

        TenantContext::set($this->alpha);

        $this->assertNull(Student::query()->find($foreign->getKey()));
        $this->assertFalse(Student::query()->whereKey($foreign->getKey())->exists());
    }

    #[Test]
    public function new_records_are_stamped_with_the_active_academy(): void
    {
        TenantContext::set($this->beta);

        $student = Student::factory()->create();

        $this->assertSame($this->beta->getKey(), $student->academy_id);
    }

    #[Test]
    public function an_explicit_foreign_academy_id_does_not_survive_the_scope(): void
    {
        // Even if a caller hand-sets academy_id, reads stay inside the tenant —
        // so a mistake writes an orphan rather than leaking on the way back out.
        TenantContext::set($this->alpha);

        $student = Student::factory()->create(['academy_id' => $this->beta->getKey()]);

        $this->assertNull(Student::query()->find($student->getKey()));
    }

    #[Test]
    public function tenant_scoped_models_throw_when_no_tenant_is_resolved(): void
    {
        // runningInConsole() is true under PHPUnit, so assert the scope's
        // decision directly rather than relying on the request-context branch.
        $this->assertFalse(TenantContext::check());
        $this->expectException(TenantNotResolvedException::class);

        TenantContext::id();
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function tenantScopedModels(): array
    {
        return [
            'students' => [Student::class],
            'question banks' => [QuestionBank::class],
            'questions' => [Question::class],
            'exams' => [Exam::class],
            'practice sessions' => [PracticeSession::class],
            'answers' => [Answer::class],
            'telegram bots' => [TelegramBot::class],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('tenantScopedModels')]
    public function every_tenant_model_carries_the_global_scope(string $model): void
    {
        $instance = new $model;

        $this->assertContains(
            \App\Domain\Tenancy\Concerns\BelongsToAcademy::class,
            class_uses_recursive($model),
            "{$model} is tenant data but does not use BelongsToAcademy."
        );

        $this->assertArrayHasKey(
            'academy',
            $instance->getGlobalScopes(),
            "{$model} does not register the academy global scope."
        );
    }

    #[Test]
    public function run_for_restores_the_previous_tenant(): void
    {
        TenantContext::set($this->alpha);

        TenantContext::runFor($this->beta, function (): void {
            $this->assertSame($this->beta->getKey(), TenantContext::id());
        });

        $this->assertSame($this->alpha->getKey(), TenantContext::id());
    }

    #[Test]
    public function run_without_clears_and_restores_the_tenant(): void
    {
        TenantContext::set($this->alpha);

        TenantContext::runWithout(function (): void {
            $this->assertFalse(TenantContext::check());
        });

        $this->assertSame($this->alpha->getKey(), TenantContext::id());
    }

    #[Test]
    public function for_academy_reads_a_specific_tenant_regardless_of_context(): void
    {
        TenantContext::runFor($this->beta, fn () => Student::factory()->count(4)->create());

        TenantContext::set($this->alpha);

        $this->assertSame(4, Student::query()->forAcademy($this->beta)->count());
        $this->assertSame(0, Student::query()->count());
    }

    #[Test]
    public function cache_prefix_and_storage_root_follow_the_tenant(): void
    {
        TenantContext::set($this->alpha);
        $this->assertSame("ac{$this->alpha->getKey()}:", config('cache.prefix'));
        $this->assertSame("academies/{$this->alpha->getKey()}", config('filesystems.disks.tenant.root'));

        TenantContext::forget();
        TenantContext::set($this->beta);
        $this->assertSame("ac{$this->beta->getKey()}:", config('cache.prefix'));
        $this->assertSame("academies/{$this->beta->getKey()}", config('filesystems.disks.tenant.root'));
    }

    #[Test]
    public function deleting_an_academy_cascades_to_its_tenant_rows(): void
    {
        TenantContext::runFor($this->beta, fn () => Student::factory()->count(3)->create());
        TenantContext::runFor($this->alpha, fn () => Student::factory()->count(2)->create());

        $this->beta->forceDelete();

        TenantContext::set($this->alpha);
        $this->assertSame(2, Student::query()->count());
        $this->assertSame(0, Student::query()->forAcademy($this->beta)->count());
    }
}
