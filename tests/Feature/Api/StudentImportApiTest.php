<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\Student;
use App\Domain\Integration\Jobs\ImportStudentsJob;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * `POST /api/v1/students/import` (CSV → job) and its polling endpoint —
 * docs/08 §3.
 */
final class StudentImportApiTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tenant');
    }

    #[Test]
    public function uploading_a_csv_stores_it_and_queues_the_job(): void
    {
        Queue::fake();

        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['students:write']);

        $response = $this->post('/api/v1/students/import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $this->csv()),
        ], $this->keyHeaders($token));

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonStructure(['data' => ['import_id', 'status'], 'meta' => ['request_id']]);

        $importId = (string) $response->json('data.import_id');

        Queue::assertPushed(
            ImportStudentsJob::class,
            fn (ImportStudentsJob $job): bool => $job->importId === $importId
                && $job->academyId === (int) $academy->getKey()
                && str_starts_with($job->path, 'imports/students/'),
        );

        Storage::disk('tenant')->assertExists(
            Queue::pushed(ImportStudentsJob::class)->first()->path,
        );

        // Poll while still queued: the marker is written before dispatch.
        $this->getJson('/api/v1/students/import/'.$importId, $this->keyHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');
    }

    #[Test]
    public function a_completed_import_reports_per_row_errors(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['students:write', 'students:read']);

        // The sync queue runs the job inside the request; polling afterwards
        // sees the finished report exactly as a production caller would.
        $response = $this->post('/api/v1/students/import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $this->csv()),
        ], $this->keyHeaders($token));

        $response->assertStatus(202);

        $importId = (string) $response->json('data.import_id');

        $poll = $this->getJson('/api/v1/students/import/'.$importId, $this->keyHeaders($token));

        $poll->assertOk()
            ->assertJsonPath('data.import_id', $importId)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_rows', 3)
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.failed', 1);

        $errors = $poll->json('data.errors');

        $this->assertCount(1, $errors);
        $this->assertSame(4, $errors[0]['line']);
        $this->assertNotEmpty($errors[0]['messages']);

        $created = TenantContext::runFor($academy, static fn (): int => Student::query()->count());

        $this->assertSame(2, $created);
    }

    #[Test]
    public function the_inline_json_array_still_imports_synchronously(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['students:write']);

        $this->postJson('/api/v1/students/import', [
            'students' => [
                ['first_name' => 'Sara', 'last_name' => 'Ahmadi'],
                ['first_name' => 'Reza'],
            ],
        ], $this->keyHeaders($token))
            ->assertStatus(201)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.failed', 0);
    }

    #[Test]
    public function polling_an_unknown_import_returns_404(): void
    {
        $academy = $this->academy('alpha');
        ['plain_text' => $token] = $this->apiKeyFor($academy, ['students:read']);

        $this->getJson('/api/v1/students/import/does-not-exist', $this->keyHeaders($token))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function an_import_is_invisible_to_another_academy(): void
    {
        $alpha = $this->academy('alpha');
        $beta = $this->academy('beta');
        ['plain_text' => $alphaToken] = $this->apiKeyFor($alpha, ['students:write']);
        ['plain_text' => $betaToken] = $this->apiKeyFor($beta, ['students:read']);

        $importId = (string) $this->post('/api/v1/students/import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $this->csv()),
        ], $this->keyHeaders($alphaToken))->json('data.import_id');

        $this->getJson('/api/v1/students/import/'.$importId, $this->keyHeaders($betaToken))
            ->assertStatus(404);
    }

    private function csv(): string
    {
        return implode("\n", [
            'first_name,last_name,email',
            'Sara,Ahmadi,sara@example.test',
            'Reza,,reza@example.test',
            ',Missing,missing@example.test',
        ]);
    }
}
