<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Assessment\Models\Exam;
use App\Domain\Reporting\Enums\ReportStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Jobs\GenerateReportJob;
use App\Domain\Reporting\Models\Report;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * `?format=xlsx` exports go through the async report pipeline: 202 + report
 * id, then `GET /api/v1/exports/{id}` for the signed URL — docs/08 §3.
 */
final class ExportApiTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tenant');
    }

    #[Test]
    public function an_xlsx_results_request_registers_a_report_and_returns_202(): void
    {
        Bus::fake();

        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['exams:read']);
        $exam = $this->examFor($academy);

        $response = $this->getJson(
            '/api/v1/exams/'.$exam->getKey().'/results?format=xlsx',
            $this->keyHeaders($token),
        );

        $response->assertStatus(202)
            ->assertJsonPath('data.format', 'xlsx')
            ->assertJsonPath('data.status', ReportStatus::Pending->value);

        $reportId = (int) $response->json('data.report_id');

        Bus::assertDispatched(
            GenerateReportJob::class,
            fn (GenerateReportJob $job): bool => $job->reportId === $reportId,
        );

        $report = TenantContext::runFor(
            $academy,
            static fn (): ?Report => Report::query()->find($reportId),
        );

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame(ReportType::ExamResults, $report->type);
        $this->assertSame('xlsx', $report->format);
        $this->assertSame((int) $exam->getKey(), (int) ($report->params['exam_id'] ?? 0));
    }

    #[Test]
    public function a_finished_export_polls_to_a_short_lived_download_url(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['exams:read']);
        $exam = $this->examFor($academy);

        // The sync queue builds the report during the request, so the poll
        // that follows sees the terminal state a production caller would
        // reach after a few retries.
        $reportId = (int) $this->getJson(
            '/api/v1/exams/'.$exam->getKey().'/results?format=xlsx',
            $this->keyHeaders($token),
        )->assertStatus(202)->json('data.report_id');

        $poll = $this->getJson('/api/v1/exports/'.$reportId, $this->keyHeaders($token));

        $poll->assertOk()
            ->assertJsonPath('data.report_id', $reportId)
            ->assertJsonPath('data.status', ReportStatus::Ready->value)
            ->assertJsonPath('data.type', ReportType::ExamResults->value);

        $this->assertNotNull($poll->json('data.download_url'));
        $this->assertNotNull($poll->json('data.expires_at'));

        $path = TenantContext::runFor(
            $academy,
            static fn (): string => (string) Report::query()->findOrFail($reportId)->file_path,
        );

        $this->assertStringEndsWith('.xlsx', $path);
        Storage::disk('tenant')->assertExists($path);
    }

    #[Test]
    public function json_results_are_still_served_inline(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['exams:read']);
        $exam = $this->examFor($academy);

        $this->getJson('/api/v1/exams/'.$exam->getKey().'/results', $this->keyHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.exam_id', (int) $exam->getKey())
            ->assertJsonPath('data.results', []);
    }

    #[Test]
    public function student_and_score_lists_queue_xlsx_exports_too(): void
    {
        Bus::fake();

        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['students:read', 'scores:read']);

        $studentsReport = (int) $this->getJson(
            '/api/v1/students?format=xlsx&status=active',
            $this->keyHeaders($token),
        )->assertStatus(202)->json('data.report_id');

        $scoresReport = (int) $this->getJson(
            '/api/v1/scores?format=xlsx&from=2026-07-01&to=2026-07-31',
            $this->keyHeaders($token),
        )->assertStatus(202)->json('data.report_id');

        $reports = TenantContext::runFor(
            $academy,
            static fn (): array => Report::query()->get()->keyBy('id')->all(),
        );

        $this->assertSame(ReportType::Students, $reports[$studentsReport]->type);
        $this->assertSame('active', $reports[$studentsReport]->params['status']);
        $this->assertSame(ReportType::Scores, $reports[$scoresReport]->type);
        $this->assertSame('2026-07-01', $reports[$scoresReport]->params['from']);

        Bus::assertDispatchedTimes(GenerateReportJob::class, 2);
    }

    #[Test]
    public function an_export_is_invisible_to_another_academy(): void
    {
        $alpha = $this->academy('alpha');
        $beta = $this->academy('beta');
        ['plain_text' => $alphaToken] = $this->apiKeyFor($alpha, ['exams:read']);
        ['plain_text' => $betaToken] = $this->apiKeyFor($beta, ['reports:read']);
        $exam = $this->examFor($alpha);

        $reportId = (int) $this->getJson(
            '/api/v1/exams/'.$exam->getKey().'/results?format=xlsx',
            $this->keyHeaders($alphaToken),
        )->json('data.report_id');

        $this->getJson('/api/v1/exports/'.$reportId, $this->keyHeaders($betaToken))
            ->assertStatus(404);
    }

    private function examFor(Academy $academy): Exam
    {
        return TenantContext::runFor(
            $academy,
            static fn (): Exam => Exam::factory()->published()->create(),
        );
    }
}
