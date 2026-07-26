<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Assessment\Actions\DeleteExam;
use App\Domain\Assessment\Actions\UpsertExamSectionQuestions;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamQuestion;
use App\Domain\Assessment\Models\ExamSection;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The exam-administration actions the Audit layer hangs off: the section
 * projection PublishExam counts, and the deletion docs/02 §7 makes auditable.
 */
final class ExamAdministrationTest extends TestCase
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
    public function chosen_questions_are_projected_in_order_with_a_derived_score(): void
    {
        $section = ExamSection::factory()->create(['score' => 30.0]);

        app(UpsertExamSectionQuestions::class)->handle($section, [101, 102, 103]);

        $rows = $section->questions()->get();

        $this->assertSame([101, 102, 103], $rows->pluck('question_id')->all());
        $this->assertSame([0, 1, 2], $rows->pluck('sort_order')->all());
        $this->assertSame([10.0, 10.0, 10.0], $rows->pluck('score')->all());
    }

    #[Test]
    public function a_second_projection_reorders_survivors_and_prunes_the_rest(): void
    {
        $section = ExamSection::factory()->create(['score' => 30.0]);
        $action = app(UpsertExamSectionQuestions::class);

        $action->handle($section, [101, 102, 103]);

        $keptRowId = (int) ExamQuestion::query()
            ->where('exam_section_id', $section->getKey())
            ->where('question_id', 103)
            ->value('id');

        $action->handle($section, [103, 101]);

        $rows = $section->questions()->get();

        $this->assertSame([103, 101], $rows->pluck('question_id')->all());
        $this->assertSame([0, 1], $rows->pluck('sort_order')->all());
        $this->assertSame([15.0, 15.0], $rows->pluck('score')->all());

        // Survivors keep their identity — updated in place, not reinserted.
        $this->assertSame($keptRowId, (int) $rows->firstWhere('question_id', 103)?->getKey());
    }

    #[Test]
    public function duplicates_collapse_and_an_empty_list_clears_the_section(): void
    {
        $section = ExamSection::factory()->create();
        $action = app(UpsertExamSectionQuestions::class);

        $action->handle($section, [101, 101, 102]);
        $this->assertSame(2, $section->questions()->count());

        $action->handle($section, []);
        $this->assertSame(0, $section->questions()->count());
    }

    #[Test]
    public function a_pool_section_scores_per_drawn_question_not_per_candidate(): void
    {
        $section = ExamSection::factory()->pool(take: 2)->create(['score' => 20.0]);

        app(UpsertExamSectionQuestions::class)->handle($section, [101, 102, 103, 104]);

        // Four candidates, but a student answers two — 10 points each.
        $this->assertSame(
            [10.0, 10.0, 10.0, 10.0],
            $section->questions()->get()->pluck('score')->all(),
        );
    }

    #[Test]
    public function switching_to_random_selection_clears_stale_explicit_rows(): void
    {
        $section = ExamSection::factory()->create();

        app(UpsertExamSectionQuestions::class)->handle($section, [101, 102]);

        $section->forceFill([
            'selection_mode' => 'random',
            'selection_config' => ['count' => 5],
        ])->save();

        app(UpsertExamSectionQuestions::class)->handle($section->refresh(), [101, 102]);

        $this->assertSame(0, ExamQuestion::query()->where('exam_section_id', $section->getKey())->count());
    }

    #[Test]
    public function deleting_an_exam_soft_deletes_and_writes_the_mandatory_audit_entry(): void
    {
        $actor = User::factory()->create();
        $exam = Exam::factory()->create(['title' => 'Mock Test 4']);

        app(DeleteExam::class)->handle($exam, (int) $actor->getKey());

        $this->assertSoftDeleted('exams', ['id' => $exam->getKey()]);

        $entry = ActivityLog::query()
            ->forAction(AuditAction::ExamDeleted->value)
            ->forSubject($exam)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('Mock Test 4', $entry->new_values['title']);
        $this->assertSame((int) $actor->getKey(), (int) $entry->new_values['deleted_by']);
        $this->assertSame((int) $this->academy->getKey(), (int) $entry->academy_id);
    }
}
