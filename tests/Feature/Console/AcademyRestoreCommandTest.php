<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Models\Student;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * The restore is exercised the way the monthly drill runs it: a real archive
 * produced by academy:export, replayed into a different academy in the same
 * database — the original rows still exist, so every primary key in the
 * archive is already taken and only a correct remap can succeed.
 */
final class AcademyRestoreCommandTest extends TestCase
{
    use RefreshDatabase;

    private Academy $source;

    private Academy $target;

    private string $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        // Deliberately not configured(): academy_domains.hostname is globally
        // unique, and restoring a domain row next to its living original is a
        // conflict no id remap can (or should) paper over.
        $this->source = Academy::factory()->create(['slug' => 'source']);
        $this->target = Academy::factory()->create(['slug' => 'target']);
        $this->archive = storage_path('app/testing/source-restore.zip');

        File::delete($this->archive);
    }

    protected function tearDown(): void
    {
        File::delete($this->archive);

        parent::tearDown();
    }

    #[Test]
    public function it_restores_an_export_into_another_academy_with_every_reference_remapped(): void
    {
        [$bank, $question, $student, $session] = $this->seedSourceAndExport();

        $this->artisan('academy:restore', ['id' => 'target', 'backup' => $this->archive])
            ->assertSuccessful();

        $targetId = (int) $this->target->getKey();

        $newBank = DB::table('question_banks')->where('academy_id', $targetId)->first();
        $newQuestion = DB::table('questions')->where('academy_id', $targetId)->first();
        $newStudent = DB::table('students')->where('academy_id', $targetId)->first();
        $newSession = DB::table('practice_sessions')->where('academy_id', $targetId)->first();
        $answers = DB::table('answers')->where('academy_id', $targetId)->get();

        $this->assertNotNull($newBank);
        $this->assertNotNull($newQuestion);
        $this->assertNotNull($newStudent);
        $this->assertNotNull($newSession);
        $this->assertCount(2, $answers);

        // Fresh primary keys — the archive's keys are in use by the source.
        $this->assertNotSame((int) $bank->getKey(), (int) $newBank->id);
        $this->assertNotSame((int) $student->getKey(), (int) $newStudent->id);

        // And every kind of reference follows the new keys: a declared FK, a
        // convention *_id the schema left unconstrained, and the polymorphic
        // session_type/session_id pair.
        $this->assertSame((int) $newBank->id, (int) $newQuestion->bank_id);
        $this->assertSame((int) $newStudent->id, (int) $newSession->student_id);

        foreach ($answers as $answer) {
            $this->assertSame((int) $newQuestion->id, (int) $answer->question_id);
            $this->assertSame((int) $newStudent->id, (int) $answer->student_id);
            $this->assertSame(SessionType::Practice->value, (string) $answer->session_type);
            $this->assertSame((int) $newSession->id, (int) $answer->session_id);
            // \N in the CSV came back as SQL NULL, not the empty string.
            $this->assertNull($answer->score);
        }

        // The source academy was not touched.
        $this->assertSame(1, DB::table('students')->where('academy_id', $this->source->getKey())->count());
        $this->assertSame((int) $session->getKey(), (int) DB::table('practice_sessions')->where('academy_id', $this->source->getKey())->value('id'));
        $this->assertSame((int) $question->getKey(), (int) DB::table('questions')->where('academy_id', $this->source->getKey())->value('id'));
    }

    #[Test]
    public function dry_run_validates_and_reports_without_writing_a_single_row(): void
    {
        $this->seedSourceAndExport();

        $this->artisan('academy:restore', [
            'id' => 'target',
            'backup' => $this->archive,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, DB::table('students')->where('academy_id', $this->target->getKey())->count());
        $this->assertSame(0, DB::table('answers')->where('academy_id', $this->target->getKey())->count());
    }

    #[Test]
    public function it_refuses_an_academy_that_already_has_data_unless_forced(): void
    {
        $this->seedSourceAndExport();

        TenantContext::runFor($this->target, fn (): Student => Student::factory()->create([
            'first_name' => 'Pre',
            'last_name' => 'Existing',
        ]));

        $this->artisan('academy:restore', ['id' => 'target', 'backup' => $this->archive])
            ->assertFailed();

        // Refusal wrote nothing.
        $this->assertSame(1, DB::table('students')->where('academy_id', $this->target->getKey())->count());

        $this->artisan('academy:restore', [
            'id' => 'target',
            'backup' => $this->archive,
            '--force' => true,
        ])->assertSuccessful();

        // --force replaced, not merged: the pre-existing student is gone and
        // exactly the archive's roster remains.
        $students = DB::table('students')->where('academy_id', $this->target->getKey())->get();

        $this->assertCount(1, $students);
        $this->assertNotSame('Pre', (string) $students[0]->first_name);
    }

    #[Test]
    public function a_row_count_that_disagrees_with_the_manifest_is_refused(): void
    {
        $this->seedSourceAndExport();
        $this->tamperManifest(function (array $manifest): array {
            $manifest['tables']['students']['rows'] = 99;

            return $manifest;
        });

        $this->artisan('academy:restore', ['id' => 'target', 'backup' => $this->archive])
            ->assertFailed();

        $this->assertSame(0, DB::table('students')->where('academy_id', $this->target->getKey())->count());
    }

    #[Test]
    public function a_schema_version_mismatch_is_refused(): void
    {
        $this->seedSourceAndExport();
        $this->tamperManifest(function (array $manifest): array {
            $manifest['schema_version'] = '1999_01_01_000000_create_ancient_table';

            return $manifest;
        });

        $this->artisan('academy:restore', ['id' => 'target', 'backup' => $this->archive])
            ->assertFailed();
    }

    #[Test]
    public function a_reference_to_a_row_the_archive_does_not_contain_aborts_the_whole_restore(): void
    {
        $this->seedSourceAndExport(danglingQuestionId: 999_999);

        $this->artisan('academy:restore', ['id' => 'target', 'backup' => $this->archive])
            ->assertFailed();

        // The transaction rolled everything back — no half-restored academy.
        foreach (['students', 'questions', 'answers', 'practice_sessions'] as $table) {
            $this->assertSame(
                0,
                DB::table($table)->where('academy_id', $this->target->getKey())->count(),
                "A failed restore left rows in {$table}.",
            );
        }
    }

    #[Test]
    public function unknown_academies_and_missing_archives_fail_cleanly(): void
    {
        $this->artisan('academy:restore', ['id' => 'nope', 'backup' => $this->archive])
            ->assertFailed();

        $this->artisan('academy:restore', ['id' => 'target', 'backup' => storage_path('app/testing/absent.zip')])
            ->assertFailed();
    }

    /**
     * @return array{0: QuestionBank, 1: Question, 2: Student, 3: PracticeSession}
     */
    private function seedSourceAndExport(?int $danglingQuestionId = null): array
    {
        $seeded = TenantContext::runFor($this->source, function () use ($danglingQuestionId): array {
            $bank = QuestionBank::factory()->create();
            $question = Question::factory()->create(['bank_id' => $bank->getKey()]);
            $student = Student::factory()->create(['first_name' => 'Reza', 'last_name' => 'Karimi']);
            $session = PracticeSession::factory()->create(['student_id' => $student->getKey()]);

            Answer::factory()
                ->count(2)
                ->forSession(SessionType::Practice, (int) $session->getKey())
                ->create([
                    'question_id' => $danglingQuestionId ?? $question->getKey(),
                    'student_id' => $student->getKey(),
                    'score' => null,
                ]);

            return [$bank, $question, $student, $session];
        });

        $this->artisan('academy:export', ['id' => 'source', '--path' => $this->archive])
            ->assertSuccessful();

        return $seeded;
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function tamperManifest(callable $mutate): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->archive) === true);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->addFromString('manifest.json', (string) json_encode($mutate($manifest)));
        $zip->close();
    }
}
