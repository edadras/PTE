<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Enums\PromptStatus;
use App\Domain\AI\Models\AiPrompt;
use App\Domain\AI\Models\AiRubric;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Question;
use App\Domain\Telegram\Enums\MenuType;
use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Telegram\Models\TelegramMenu;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The record-scoped pages carry the heavy forms — the type-switching question
 * editor, the exam builder, the menu builder. They only fail once a real record
 * is hydrated into them, so each one is opened here at least once.
 */
final class AcademyRecordPagesTest extends FilamentTestCase
{
    use RefreshDatabase;

    private Academy $academy;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academy = $this->makeAcademy('epsilon');
        $this->forgetHostCache($this->academy);
        $this->owner = $this->makeStaff($this->academy, SystemRole::Owner);
    }

    #[Test]
    public function every_record_page_renders(): void
    {
        /** @var array<string, string> $urls */
        $urls = TenantContext::runFor($this->academy, function (): array {
            $student = Student::factory()->create();
            $question = Question::factory()->create();
            $course = Course::factory()->create();

            /** @var Exam $exam */
            $exam = Exam::factory()->create();

            /** @var ExamSession $session */
            $session = ExamSession::factory()->create([
                'exam_id' => $exam->getKey(),
                'student_id' => $student->getKey(),
            ]);

            /** @var Answer $answer */
            $answer = Answer::factory()->create([
                'question_id' => $question->getKey(),
                'student_id' => $student->getKey(),
                'session_type' => SessionType::Exam,
                'session_id' => $session->getKey(),
            ]);

            /** @var TelegramMenu $menu */
            $menu = TelegramMenu::factory()->create([
                'type' => MenuType::Main,
                'status' => PublishStatus::Draft,
            ]);

            /** @var AiPrompt $prompt */
            $prompt = AiPrompt::factory()->create([
                'academy_id' => TenantContext::id(),
                'key' => AiTaskKey::SpeakingReadAloud,
                'status' => PromptStatus::Draft,
            ]);

            /** @var AiRubric $rubric */
            $rubric = AiRubric::factory()->create([
                'academy_id' => TenantContext::id(),
                'task_key' => AiTaskKey::SpeakingReadAloud,
            ]);

            return [
                'students/'.$student->getKey() => 'student view',
                'students/'.$student->getKey().'/edit' => 'student edit',
                'questions/'.$question->getKey().'/edit' => 'question edit',
                'courses/'.$course->getKey().'/edit' => 'course edit',
                'exams/'.$exam->getKey().'/edit' => 'exam edit',
                'exam-sessions/'.$session->getKey() => 'exam session view',
                'answers/'.$answer->getKey() => 'answer view',
                'telegram-menus/'.$menu->getKey().'/edit' => 'menu builder',
                'ai-prompts/'.$prompt->getKey().'/edit' => 'prompt builder',
                'ai-rubrics/'.$rubric->getKey().'/edit' => 'rubric editor',
            ];
        });

        foreach ($urls as $path => $label) {
            $response = $this->actingAs($this->owner)->get($this->panelUrl($this->academy, $path));

            $this->assertTrue(
                $response->isSuccessful(),
                sprintf('The %s page (%s) returned %d.', $label, $path, $response->status()),
            );
        }
    }
}
