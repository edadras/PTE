<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\AI\Models\AiRequest;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Models\Score;
use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Models\Question;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `/api/v1/reports/dashboard` and `/api/v1/reports/ai-usage` — docs/08 §3.
 *
 * ApiReportReader now delegates its period counters to Reporting's
 * DashboardStats; these tests pin the payload shape and the numbers so the
 * delegation cannot drift from what integrations already parse.
 */
final class ReportReaderTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_dashboard_payload_keeps_its_shape_and_numbers(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['reports:read']);

        TenantContext::runFor($academy, function (): void {
            $students = Student::factory()->count(3)->create();
            $students->first()->forceFill(['status' => StudentStatus::Inactive])->save();

            Question::factory()->count(2)->create();
            PracticeSession::factory()->count(2)->create(['student_id' => $students->first()->getKey()]);
            ExamSession::factory()->create(['student_id' => $students->first()->getKey()]);

            foreach ([50.0, 71.0] as $percentage) {
                Score::query()->create([
                    'student_id' => $students->first()->getKey(),
                    'session_type' => 'exam',
                    'session_id' => 1,
                    'raw_score' => $percentage,
                    'percentage' => $percentage,
                ]);
            }
        });

        $response = $this->getJson('/api/v1/reports/dashboard', $this->keyHeaders($token));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'students' => ['total', 'active', 'new_in_period'],
                    'content' => ['questions'],
                    'activity' => ['practice_sessions', 'exam_sessions', 'scores', 'average_percentage'],
                    'period' => ['from', 'to'],
                ],
                'meta' => ['request_id'],
            ])
            ->assertJsonPath('data.students.total', 3)
            ->assertJsonPath('data.students.active', 2)
            ->assertJsonPath('data.students.new_in_period', 3)
            ->assertJsonPath('data.content.questions', 2)
            ->assertJsonPath('data.activity.practice_sessions', 2)
            ->assertJsonPath('data.activity.exam_sessions', 1)
            ->assertJsonPath('data.activity.scores', 2)
            ->assertJsonPath('data.activity.average_percentage', 60.5);
    }

    #[Test]
    public function the_dashboard_never_counts_another_academys_activity(): void
    {
        $alpha = $this->academy('alpha');
        $beta = $this->academy('beta');
        ['plain_text' => $token] = $this->apiKeyFor($alpha, ['reports:read']);

        TenantContext::runFor($beta, function (): void {
            Student::factory()->count(4)->create();
            PracticeSession::factory()->create(['student_id' => 1]);
        });

        $this->getJson('/api/v1/reports/dashboard', $this->keyHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.students.total', 0)
            ->assertJsonPath('data.activity.practice_sessions', 0);
    }

    #[Test]
    public function ai_usage_totals_the_requested_month(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['reports:read']);

        TenantContext::runFor($academy, function () use ($academy): void {
            // The AiRequest factory always mints its own academy; pin it here.
            AiRequest::factory()->count(2)->create([
                'academy_id' => $academy->getKey(),
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
                'cost_usd' => 0.5,
                'cache_hit' => false,
            ]);
            AiRequest::factory()->create([
                'academy_id' => $academy->getKey(),
                'status' => 'failed',
                'prompt_tokens' => 10,
                'completion_tokens' => 0,
                'total_tokens' => 10,
                'cost_usd' => 0.25,
                'cache_hit' => true,
            ]);
        });

        $this->getJson(
            '/api/v1/reports/ai-usage?period='.now()->format('Y-m'),
            $this->keyHeaders($token),
        )
            ->assertOk()
            ->assertJsonPath('data.period', now()->format('Y-m'))
            ->assertJsonPath('data.requests', 3)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.cache_hits', 1)
            ->assertJsonPath('data.prompt_tokens', 210)
            ->assertJsonPath('data.completion_tokens', 100)
            ->assertJsonPath('data.total_tokens', 310)
            ->assertJsonPath('data.cost_usd', 1.25)
            ->assertJsonStructure(['data' => ['quota']]);
    }
}
