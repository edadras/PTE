<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Learning\Actions\ImportQuestions;
use App\Domain\Learning\Enums\MediaKind;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Exceptions\QuestionImportException;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Learning\Models\QuestionMedia;
use App\Domain\Learning\Support\MediaBundle;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * The media half of the question import (docs/05 §3): a ZIP whose entries the
 * CSV's audio_file / image_file columns reference by name.
 */
final class QuestionImportMediaBundleTest extends TestCase
{
    use RefreshDatabase;

    private QuestionBank $bank;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tenant');

        $academy = Academy::factory()->configured()->create();
        TenantContext::set($academy);

        $this->bank = QuestionBank::factory()->create();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_stores_bundle_files_and_creates_question_media_rows(): void
    {
        $csv = $this->csv([
            ['RS', 'Please repeat this sentence.', '30', 'sentence.mp3', '', ''],
            ['DI', '', '', '', 'diagram.png', '25'],
        ]);

        $zip = $this->zip([
            'sentence.mp3' => $this->mp3Bytes(),
            'nested/diagram.png' => $this->pngBytes(),
        ]);

        $report = app(ImportQuestions::class)->handle($csv, $this->bank, [], new MediaBundle($zip));

        $this->assertSame(2, $report->imported);
        $this->assertSame([], $report->errors);

        $audio = Question::query()->where('type', QuestionType::RepeatSentence)->firstOrFail();
        $image = Question::query()->where('type', QuestionType::DescribeImage)->firstOrFail();

        $audioKey = (string) $audio->content['audio_key'];
        $imageKey = (string) $image->content['image_key'];

        // Stored under the panel's own media folders, matched by basename even
        // when the archive nests the file in a directory.
        $this->assertStringStartsWith('questions/audio/sentence-', $audioKey);
        $this->assertStringEndsWith('.mp3', $audioKey);
        $this->assertStringStartsWith('questions/images/diagram-', $imageKey);
        $this->assertStringEndsWith('.png', $imageKey);

        Storage::disk('tenant')->assertExists($audioKey);
        Storage::disk('tenant')->assertExists($imageKey);

        $audioMedia = QuestionMedia::query()->where('question_id', $audio->getKey())->firstOrFail();

        $this->assertSame(MediaKind::Audio, $audioMedia->kind);
        $this->assertSame($audioKey, $audioMedia->s3_path);
        $this->assertSame('audio/mpeg', $audioMedia->mime);
        $this->assertSame(strlen($this->mp3Bytes()), $audioMedia->size_bytes);

        $imageMedia = QuestionMedia::query()->where('question_id', $image->getKey())->firstOrFail();

        $this->assertSame(MediaKind::Image, $imageMedia->kind);
        $this->assertSame('image/png', $imageMedia->mime);
    }

    #[Test]
    public function the_csv_only_path_is_unchanged_when_no_bundle_is_given(): void
    {
        // Without a bundle, audio_file stays ignored — the row fails on the
        // missing audio_key exactly as it always has.
        $csv = $this->csv([['RS', 'Please repeat this sentence.', '30', 'sentence.mp3', '', '']]);

        try {
            app(ImportQuestions::class)->handle($csv, $this->bank);
            $this->fail('The CSV-only import accepted a row that needs media.');
        } catch (QuestionImportException $e) {
            $this->assertSame(1, $e->report->failedCount());
        }

        $this->assertSame(0, Question::query()->count());
    }

    #[Test]
    public function a_missing_bundle_entry_rolls_back_and_purges_every_staged_file(): void
    {
        $csv = $this->csv([
            ['RS', 'Please repeat this sentence.', '30', 'sentence.mp3', '', ''],
            ['RS', 'Another sentence to repeat.', '30', 'missing.mp3', '', ''],
        ]);

        $zip = $this->zip(['sentence.mp3' => $this->mp3Bytes()]);

        try {
            app(ImportQuestions::class)->handle($csv, $this->bank, [], new MediaBundle($zip));
            $this->fail('A row referencing an absent media file was accepted.');
        } catch (QuestionImportException $e) {
            $messages = implode(' ', $e->report->errors[0]->messages);
            $this->assertStringContainsString('missing.mp3', $messages);
        }

        $this->assertSame(0, Question::query()->count());
        // sentence.mp3 was staged before the second row failed; the rollback
        // must not leave it orphaned on the tenant disk.
        $this->assertSame([], Storage::disk('tenant')->allFiles());
    }

    #[Test]
    public function a_file_is_judged_by_its_bytes_not_its_extension(): void
    {
        $csv = $this->csv([['RS', 'Please repeat this sentence.', '30', 'sentence.mp3', '', '']]);
        $zip = $this->zip(['sentence.mp3' => 'this is a plain text file wearing an mp3 extension']);

        try {
            app(ImportQuestions::class)->handle($csv, $this->bank, [], new MediaBundle($zip));
            $this->fail('A text file with an .mp3 name was accepted as audio.');
        } catch (QuestionImportException $e) {
            $messages = implode(' ', $e->report->errors[0]->messages);
            $this->assertStringContainsString('text/plain', $messages);
        }

        $this->assertSame([], Storage::disk('tenant')->allFiles());
    }

    #[Test]
    public function a_bundle_with_a_traversal_entry_is_refused_outright(): void
    {
        $zip = $this->zip(['../evil.mp3' => $this->mp3Bytes()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/evil\.mp3/');

        new MediaBundle($zip);
    }

    #[Test]
    public function the_size_cap_is_enforced_on_actual_content(): void
    {
        $csv = $this->csv([['RS', 'Please repeat this sentence.', '30', 'sentence.mp3', '', '']]);
        $zip = $this->zip(['sentence.mp3' => $this->mp3Bytes()]);

        try {
            app(ImportQuestions::class)->handle($csv, $this->bank, [], new MediaBundle($zip, maxBytes: 10));
            $this->fail('A file over the size cap was accepted.');
        } catch (QuestionImportException $e) {
            $messages = implode(' ', $e->report->errors[0]->messages);
            $this->assertStringContainsString('10 bytes', $messages);
        }

        $this->assertSame([], Storage::disk('tenant')->allFiles());
    }

    #[Test]
    public function dry_run_validates_the_bundle_but_stores_nothing(): void
    {
        $csv = $this->csv([['RS', 'Please repeat this sentence.', '30', 'sentence.mp3', '', '']]);
        $zip = $this->zip(['sentence.mp3' => $this->mp3Bytes()]);

        $report = app(ImportQuestions::class)->dryRun($csv, $this->bank, [], new MediaBundle($zip));

        $this->assertSame(1, $report->imported);
        $this->assertSame([], $report->errors);
        $this->assertSame(0, Question::query()->count());
        $this->assertSame([], Storage::disk('tenant')->allFiles());
    }

    /**
     * @param  array<int, array<int, string>>  $rows  [type, transcript, record_seconds, audio_file, image_file, prep_seconds]
     */
    private function csv(array $rows): string
    {
        $path = $this->tempFile('csv');
        $handle = fopen($path, 'wb');

        fputcsv($handle, ['type', 'transcript', 'record_seconds', 'audio_file', 'image_file', 'prep_seconds'], ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        fclose($handle);

        return $path;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zip(array $entries): string
    {
        $path = $this->tempFile('zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->close();

        return $path;
    }

    private function tempFile(string $suffix): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pte-import-'.$suffix);

        $this->tempFiles[] = $path;

        return $path;
    }

    private function mp3Bytes(): string
    {
        return "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x00", 24);
    }

    private function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }
}
