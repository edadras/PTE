<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Actions\IssueStudentToken;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Learning\Support\ModuleRegistry;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyModule;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end shape of the Student API (docs/08 §3).
 */
final class StudentApiFlowTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_brand_endpoint_is_reachable_without_a_token(): void
    {
        $academy = $this->academy('alpha');
        $host = $this->hostFor($academy);

        $this->getJson('http://'.$host.'/api/student/v1/brand')
            ->assertOk()
            ->assertJsonStructure(['data' => ['display_name', 'palette'], 'meta' => ['request_id']]);
    }

    #[Test]
    public function an_unknown_host_is_a_404_and_never_reveals_the_platform(): void
    {
        $this->getJson('http://nobody.pte-platform.test/api/student/v1/brand')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function a_student_can_run_a_full_practice_session(): void
    {
        $academy = $this->academy('alpha');
        $host = $this->hostFor($academy);

        [$student, $token] = $this->studentWithToken($academy);

        $start = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('http://'.$host.'/api/student/v1/practice/start', [
                'module' => ModuleKey::PteReading->value,
                'type' => QuestionType::MultipleChoiceReading->value,
                'count' => 2,
            ]);

        $start->assertStatus(201)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.student_id', (int) $student->getKey());

        $sessionId = (int) $start->json('data.id');
        $questionId = (int) $start->json('data.next_question_id');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('http://'.$host.'/api/student/v1/practice/'.$sessionId)
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('http://'.$host.'/api/student/v1/practice/'.$sessionId.'/answer', [
                'question_id' => $questionId,
                'answer' => ['option' => 'A'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.question_id', $questionId);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('http://'.$host.'/api/student/v1/practice/'.$sessionId.'/finish')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    #[Test]
    public function a_student_cannot_touch_another_students_session(): void
    {
        $academy = $this->academy('alpha');
        $host = $this->hostFor($academy);

        [, $tokenA] = $this->studentWithToken($academy);
        [, $tokenB] = $this->studentWithToken($academy);

        $start = $this->withHeaders(['Authorization' => 'Bearer '.$tokenA])
            ->postJson('http://'.$host.'/api/student/v1/practice/start', [
                'module' => ModuleKey::PteReading->value,
                'type' => QuestionType::MultipleChoiceReading->value,
                'count' => 1,
            ])->assertStatus(201);

        $this->withHeaders(['Authorization' => 'Bearer '.$tokenB])
            ->getJson('http://'.$host.'/api/student/v1/practice/'.$start->json('data.id'))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function a_student_opens_a_support_ticket_as_themselves(): void
    {
        $academy = $this->academy('alpha');
        $host = $this->hostFor($academy);

        [$student, $token] = $this->studentWithToken($academy);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('http://'.$host.'/api/student/v1/support/tickets', [
                'subject' => 'Audio will not upload',
                'message' => 'The recorder stops after two seconds on my phone.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.source', 'api');

        $this->assertDatabaseHas('support_tickets', [
            'academy_id' => $academy->getKey(),
            'student_id' => $student->getKey(),
        ]);
    }

    #[Test]
    public function the_profile_endpoint_never_returns_contact_details(): void
    {
        $academy = $this->academy('alpha');
        $host = $this->hostFor($academy);

        [, $token] = $this->studentWithToken($academy);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('http://'.$host.'/api/student/v1/me')
            ->assertOk();

        $this->assertArrayNotHasKey('phone', (array) $response->json('data'));
        $this->assertArrayNotHasKey('email', (array) $response->json('data'));
    }

    /**
     * @return array{0: Student, 1: string}
     */
    private function studentWithToken(Academy $academy): array
    {
        return TenantContext::runFor($academy, static function () use ($academy): array {
            AcademyModule::query()->firstOrCreate(
                ['academy_id' => $academy->getKey(), 'module_key' => ModuleKey::PteReading->value],
                ['is_enabled' => true, 'enabled_at' => now()],
            );

            ModuleRegistry::flushCache();

            $student = Student::factory()->create();

            /** @var QuestionBank $bank */
            $bank = QuestionBank::query()->firstOrCreate(
                ['name' => 'Default'],
                ['module_key' => ModuleKey::PteReading->value, 'is_default' => true],
            );

            Question::factory()
                ->count(3)
                ->ofType(QuestionType::MultipleChoiceReading)
                ->create([
                    'bank_id' => $bank->getKey(),
                    'status' => QuestionStatus::Published,
                    // QuestionSelector requires the approval stamp, not just
                    // the published status.
                    'approved_at' => now(),
                    'published_at' => now(),
                ]);

            return [$student, app(IssueStudentToken::class)->handle($student)['token']];
        });
    }
}
