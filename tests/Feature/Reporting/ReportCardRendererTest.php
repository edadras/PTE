<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Assessment\Enums\SessionStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Models\StudentProgress;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Reporting\Services\ReportCardRenderer;
use App\Domain\Reporting\Services\StudentProgressReport;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyBrand;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReportCardRendererTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academy = Academy::factory()->configured()->create();
        TenantContext::set($this->academy);

        AcademyBrand::query()->where('academy_id', $this->academy->getKey())->update([
            'display_name' => 'Parsian Language Centre',
            'primary_color' => '#0A7C4A',
            'secondary_color' => '#123456',
            'footer_text' => 'parsian.example',
        ]);

        $this->student = Student::factory()->create(['first_name' => 'Sara', 'last_name' => 'Ahmadi']);
    }

    #[Test]
    public function it_renders_the_card_in_the_academys_own_colours(): void
    {
        $session = $this->scoredPracticeSession();

        $html = app(ReportCardRenderer::class)->forSession($session);

        $this->assertStringContainsString('Parsian Language Centre', $html);
        $this->assertStringContainsString('#0A7C4A', $html);
        $this->assertStringContainsString('parsian.example', $html);
        $this->assertStringContainsString('Sara Ahmadi', $html);
        // Persian locale means an RTL document.
        $this->assertStringContainsString('dir="rtl"', $html);
    }

    #[Test]
    public function the_percentage_and_the_question_type_breakdown_appear(): void
    {
        $session = $this->scoredPracticeSession();

        $html = app(ReportCardRenderer::class)->forSession($session);

        // 120 of 180 marks.
        $this->assertStringContainsString('66.7%', $html);
        $this->assertStringContainsString(QuestionType::WriteFromDictation->label(), $html);
    }

    #[Test]
    public function the_markup_is_self_contained(): void
    {
        $html = app(ReportCardRenderer::class)->forSession($this->scoredPracticeSession());

        $this->assertStringNotContainsString('<link ', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<style>', $html);
    }

    #[Test]
    public function the_progress_report_separates_strengths_from_weaknesses(): void
    {
        $this->progress(QuestionType::WriteFromDictation, attempts: 10, average: 88.0);
        $this->progress(QuestionType::Essay, attempts: 8, average: 41.0);
        // Too few attempts to call it either way.
        $this->progress(QuestionType::ReadAloud, attempts: 1, average: 20.0);

        $report = app(StudentProgressReport::class)->build($this->student);

        $this->assertSame(['WFD'], array_column($report['strengths'], 'type'));
        $this->assertSame(['ESSAY'], array_column($report['weaknesses'], 'type'));
        $this->assertContains('SST', $report['untouched']);

        // Weighted by attempts: (88*10 + 41*8 + 20*1) / 19
        $this->assertSame(64.63, $report['overall']['percentage']);
    }

    private function scoredPracticeSession(): PracticeSession
    {
        $session = PracticeSession::factory()->create([
            'student_id' => $this->student->getKey(),
            'status' => SessionStatus::Completed,
            'total_questions' => 2,
            'answered' => 2,
            'total_score' => 120,
            'max_score' => 180,
        ]);

        foreach ([70.0, 50.0] as $score) {
            Answer::factory()->create([
                'student_id' => $this->student->getKey(),
                'session_type' => SessionType::Practice,
                'session_id' => $session->getKey(),
                'answer_data' => ['question_type' => QuestionType::WriteFromDictation->value],
                'score' => $score,
                'max_score' => 90,
            ]);
        }

        return $session;
    }

    private function progress(QuestionType $type, int $attempts, float $average): void
    {
        StudentProgress::query()->create([
            'student_id' => $this->student->getKey(),
            'module_key' => $type->module()->value,
            'question_type' => $type->value,
            'attempts' => $attempts,
            'avg_score' => $average,
            'best_score' => $average,
            'last_score' => $average,
            'last_practiced_at' => now(),
        ]);
    }
}
